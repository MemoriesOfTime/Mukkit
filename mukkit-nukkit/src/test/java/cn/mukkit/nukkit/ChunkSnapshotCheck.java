package cn.mukkit.nukkit;

import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.Base64;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.zip.Inflater;

/**
 * Emits a chunk section encoded exactly the way the live server encodes it, for the PHP host to
 * decode.
 *
 * <p>The point is the seam, not the arithmetic: the two sides disagreeing about index order or
 * compression would look like "the mirror is broken" in game and like nothing at all in either
 * language's own tests. Here the bytes cross the language boundary.</p>
 */
public final class ChunkSnapshotCheck {

    /** XZY order, matching PocketMine's PalettedBlockArray. */
    private static int index(int x, int y, int z) {
        return x | (z << 4) | (y << 8);
    }

    public static void main(String[] args) throws Exception {
        if (args.length < 1) {
            System.err.println("usage: ChunkSnapshotCheck <output.json>");
            System.exit(2);
        }

        short[] ids = new short[4096];
        byte[] metas = new byte[4096];
        // A pattern that fails loudly if the index order is transposed: each block sits on a
        // different axis, and one of them carries a non-zero meta.
        ids[index(0, 0, 0)] = 1;    // stone
        ids[index(1, 0, 0)] = 3;    // dirt
        ids[index(0, 0, 1)] = 5;    // planks
        ids[index(0, 1, 0)] = 1;    // stone one block up
        metas[index(0, 0, 1)] = 2;  // planks with a non-zero damage value

        Map<String, Object> section = ChunkSnapshot.encodeSection(4, ids, metas);

        // Decode our own payload: a mistake in the packing would otherwise only show up as
        // "wrong block" on the other side, with no hint about which side is wrong.
        short[] backIds = new short[4096];
        byte[] backMetas = new byte[4096];
        byte[] idBytes = inflate(String.valueOf(section.get("ids")));
        byte[] metaBytes = inflate(String.valueOf(section.get("metas")));
        check("ids payload is 8192 bytes", idBytes.length == 8192, String.valueOf(idBytes.length));
        check("metas payload is 4096 bytes", metaBytes.length == 4096, String.valueOf(metaBytes.length));
        for (int i = 0; i < 4096; i++) {
            backIds[i] = (short) ((idBytes[i * 2] & 0xff) | ((idBytes[i * 2 + 1] & 0xff) << 8));
            backMetas[i] = metaBytes[i];
        }
        check("stone decodes at 0,0,0", backIds[index(0, 0, 0)] == 1);
        check("dirt decodes at 1,0,0", backIds[index(1, 0, 0)] == 3);
        check("planks decode at 0,0,1 with meta 2",
                backIds[index(0, 0, 1)] == 5 && backMetas[index(0, 0, 1)] == 2);
        check("stone decodes at 0,1,0", backIds[index(0, 1, 0)] == 1);
        check("an untouched position is air", backIds[index(5, 5, 5)] == 0 && backMetas[index(5, 5, 5)] == 0);

        Map<String, Object> fixture = new LinkedHashMap<>(2);
        fixture.put("sections", List.of(section));
        Files.writeString(Path.of(args[0]), toJson(fixture), StandardCharsets.UTF_8);

        System.out.println("m5-jvm PASS  fixture written to " + args[0]);
    }

    private static byte[] inflate(String base64) throws Exception {
        byte[] compressed = Base64.getDecoder().decode(base64);
        Inflater inflater = new Inflater();
        try {
            inflater.setInput(compressed);
            byte[] out = new byte[8192];
            int n = inflater.inflate(out);
            byte[] exact = new byte[n];
            System.arraycopy(out, 0, exact, 0, n);
            return exact;
        } finally {
            inflater.end();
        }
    }

    private static void check(String label, boolean ok) {
        check(label, ok, "");
    }

    private static void check(String label, boolean ok, String detail) {
        if (!ok) {
            System.out.println("FAIL  " + label + (detail.isEmpty() ? "" : " (" + detail + ")"));
            System.exit(1);
        }
        System.out.println("ok    " + label);
    }

    /** Tiny serialiser: the fixture is one section with three string/int fields. */
    private static String toJson(Map<String, Object> fixture) {
        StringBuilder out = new StringBuilder("{\"sections\":[");
        @SuppressWarnings("unchecked")
        List<Map<String, Object>> sections = (List<Map<String, Object>>) fixture.get("sections");
        for (int i = 0; i < sections.size(); i++) {
            Map<String, Object> section = sections.get(i);
            if (i > 0) {
                out.append(',');
            }
            out.append("{\"y\":").append(section.get("y"))
                    .append(",\"ids\":\"").append(section.get("ids"))
                    .append("\",\"metas\":\"").append(section.get("metas")).append("\"}");
        }
        return out.append("]}").toString();
    }
}
