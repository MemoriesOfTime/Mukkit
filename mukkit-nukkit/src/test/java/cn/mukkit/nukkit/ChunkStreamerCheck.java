package cn.mukkit.nukkit;

import java.util.Map;
import java.util.Set;

/**
 * Offline checks for {@link ChunkStreamer}'s arithmetic.
 *
 * <p>What the streaming loop does is easy to see; what it computes is not. A chunk key packs two
 * signed 32-bit coordinates into one long, and getting the sign extension wrong makes the mirror
 * subscribe to chunk 4294967295 instead of -1 - which does not throw, does not log, and simply
 * never matches, so the host keeps reading air in the entire negative quadrant. The square around
 * a player has the same property: one off-by-one and the chunk the player is standing in is
 * missing.</p>
 */
public final class ChunkStreamerCheck {

    private static int checks;
    private static int failures;

    public static void main(String[] args) {
        // ---------------------------------------------------------------- chunk keys
        check("a key round-trips positive coordinates",
                ChunkStreamer.xOf(ChunkStreamer.chunkKey(3, 7)) == 3
                        && ChunkStreamer.zOf(ChunkStreamer.chunkKey(3, 7)) == 7);
        check("a key round-trips negative coordinates",
                ChunkStreamer.xOf(ChunkStreamer.chunkKey(-5, -9)) == -5
                        && ChunkStreamer.zOf(ChunkStreamer.chunkKey(-5, -9)) == -9);
        check("the extremes survive",
                ChunkStreamer.xOf(ChunkStreamer.chunkKey(Integer.MIN_VALUE, Integer.MAX_VALUE)) == Integer.MIN_VALUE
                        && ChunkStreamer.zOf(ChunkStreamer.chunkKey(Integer.MIN_VALUE, Integer.MAX_VALUE)) == Integer.MAX_VALUE);
        check("different chunks get different keys",
                ChunkStreamer.chunkKey(1, 2) != ChunkStreamer.chunkKey(2, 1));
        check("the same chunk always gets the same key",
                ChunkStreamer.chunkKey(-1, 4) == ChunkStreamer.chunkKey(-1, 4));

        // ---------------------------------------------------------------- the square
        Set<Long> one = ChunkStreamer.around(0, 0, 1);
        check("radius 1 is a 3x3 square", one.size() == 9);
        check("the square contains its centre", one.contains(ChunkStreamer.chunkKey(0, 0)));
        check("the square reaches every corner",
                one.contains(ChunkStreamer.chunkKey(-1, -1)) && one.contains(ChunkStreamer.chunkKey(1, 1))
                        && one.contains(ChunkStreamer.chunkKey(-1, 1)) && one.contains(ChunkStreamer.chunkKey(1, -1)));
        check("the square stops at the radius", !one.contains(ChunkStreamer.chunkKey(2, 0)));

        check("radius 0 is the player's own chunk",
                ChunkStreamer.around(12, -7, 0).equals(Set.of(ChunkStreamer.chunkKey(12, -7))));

        Set<Long> two = ChunkStreamer.around(-3, 5, 2);
        check("radius 2 is a 5x5 square", two.size() == 25);
        check("the centre of a negative square is right", two.contains(ChunkStreamer.chunkKey(-3, 5)));
        check("a negative square does not drift", two.contains(ChunkStreamer.chunkKey(-5, 3)));

        // ---------------------------------------------------------------- unload frame
        Map<String, Object> frame = ChunkStreamer.unloadFrame("arena", -2, 11);
        check("an unload frame names its kind", "chunk.unload".equals(frame.get("k")));
        check("an unload frame names its world", "arena".equals(frame.get("world")));
        check("an unload frame carries both coordinates",
                Integer.valueOf(-2).equals(frame.get("x")) && Integer.valueOf(11).equals(frame.get("z")));

        System.out.println("chunk-streamer " + (failures == 0 ? "PASS" : "FAILED")
                + " (" + checks + " checks)");
        if (failures > 0) {
            System.exit(1);
        }
    }

    private static void check(String label, boolean ok) {
        checks++;
        if (ok) {
            System.out.println("ok    " + label);
        } else {
            failures++;
            System.out.println("FAIL  " + label);
        }
    }

    private ChunkStreamerCheck() {
    }
}
