package cn.mukkit.nukkit;

import cn.nukkit.blockentity.BlockEntity;
import cn.nukkit.level.DimensionData;
import cn.nukkit.level.Level;
import cn.nukkit.level.format.generic.BaseFullChunk;

import java.io.ByteArrayOutputStream;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.zip.Deflater;

/**
 * Builds the chunk frames the PHP host mirrors its world from.
 *
 * <p>The payload is deliberately Nukkit's dialect - 16-bit block IDs and 4-bit damage values -
 * and never Bedrock's runtime IDs. Runtime IDs are version-scoped on both sides (PocketMine is
 * pinned to one Bedrock version, the client can be on another), so shipping them would couple
 * the mirror to whichever version happened to build it. Legacy ID + meta is the one numbering
 * both servers have agreed on for a decade.</p>
 *
 * <p>Sections are skipped when empty: a missing section means air on the receiving side, and
 * most of a 384-block column is air.</p>
 */
final class ChunkSnapshot {

    private ChunkSnapshot() {
    }

    /**
     * @return list of section frames, each {@code {y, ids, metas}} with base64(zlib) payloads
     */
    static List<Map<String, Object>> sections(Level level, int chunkX, int chunkZ) {
        BaseFullChunk chunk = level.getChunk(chunkX, chunkZ, true);
        DimensionData dimension = level.getDimensionData();

        List<Map<String, Object>> out = new ArrayList<>();
        for (int sectionY = dimension.getMinSectionY(); sectionY <= dimension.getMaxSectionY(); sectionY++) {
            int baseY = sectionY << 4;
            short[] ids = new short[4096];
            byte[] metas = new byte[4096];
            boolean empty = true;

            for (int y = 0; y < 16; y++) {
                int worldY = baseY + y;
                for (int z = 0; z < 16; z++) {
                    for (int x = 0; x < 16; x++) {
                        // XZY order: x varies fastest, then z, then y - same as PocketMine's
                        // PalettedBlockArray. Getting this wrong silently transposes the world.
                        int i = x | (z << 4) | (y << 8);
                        int id = chunk.getBlockIdAt(x, worldY, z);
                        int meta = chunk.getBlockDataAt(x, worldY, z);
                        ids[i] = (short) (id & 0xffff);
                        metas[i] = (byte) (meta & 0x0f);
                        if (id != 0 || meta != 0) {
                            empty = false;
                        }
                    }
                }
            }

            if (empty) {
                continue;
            }

            out.add(encodeSection(sectionY, ids, metas));
        }
        return out;
    }

    /**
     * Encodes one section. Split out from {@link #sections} so the wire layout can be checked
     * without a running server - the acceptance script compares this exact payload against what
     * the PHP host decodes.
     */
    static Map<String, Object> encodeSection(int sectionY, short[] ids, byte[] metas) {
        byte[] idBytes = new byte[8192];
        for (int i = 0; i < 4096; i++) {
            idBytes[i * 2] = (byte) (ids[i] & 0xff);
            idBytes[i * 2 + 1] = (byte) ((ids[i] >> 8) & 0xff);
        }
        Map<String, Object> section = new LinkedHashMap<>(4);
        section.put("y", sectionY);
        section.put("ids", deflateBase64(idBytes));
        section.put("metas", deflateBase64(metas));
        return section;
    }

    static Map<String, Object> frame(Level level, int chunkX, int chunkZ) {
        Map<String, Object> frame = new LinkedHashMap<>(6);
        frame.put("k", "chunk");
        frame.put("world", level.getName());
        frame.put("x", chunkX);
        frame.put("z", chunkZ);
        frame.put("sections", sections(level, chunkX, chunkZ));
        // Block entities travel with the chunk they belong to. Sending them separately would
        // leave a window where the host has the blocks but every chest is still `getTile() ===
        // null`, and a plugin that runs on chunk arrival would read an empty container.
        frame.put("tiles", tiles(level, chunkX, chunkZ));
        return frame;
    }

    /** The chunk's block entities the host can represent, in the bridge tile dialect. */
    static List<Map<String, Object>> tiles(Level level, int chunkX, int chunkZ) {
        BaseFullChunk chunk = level.getChunk(chunkX, chunkZ, true);
        List<Map<String, Object>> out = new ArrayList<>();
        for (BlockEntity blockEntity : chunk.getBlockEntities().values()) {
            Map<String, Object> payload = TileCodec.encode(blockEntity);
            if (payload != null) {
                out.add(payload);
            }
        }
        return out;
    }

    /**
     * zlib (not gzip): PHP's {@code gzuncompress()} is the matching decoder.
     */
    static String deflateBase64(byte[] raw) {
        Deflater deflater = new Deflater(Deflater.BEST_SPEED);
        try {
            deflater.setInput(raw);
            deflater.finish();
            ByteArrayOutputStream out = new ByteArrayOutputStream(Math.max(64, raw.length / 8));
            byte[] buffer = new byte[8192];
            while (!deflater.finished()) {
                int n = deflater.deflate(buffer);
                out.write(buffer, 0, n);
            }
            return java.util.Base64.getEncoder().encodeToString(out.toByteArray());
        } finally {
            deflater.end();
        }
    }
}
