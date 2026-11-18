package cn.mukkit.nukkit;

import java.util.List;

/**
 * Checks the position packing used by {@link TileMirror}'s shadow.
 *
 * <p>Pure arithmetic, so it runs without a server: a collision or a sign error here would make
 * one tile's change overwrite another's, and the symptom (a chest that stops updating when a
 * second chest is nearby) is much harder to find on a live server.</p>
 */
public final class TilePackingCheck {

    private static int failures = 0;

    public static void main(String[] args) {
        roundTrip(0, 0, 0);
        roundTrip(10, 64, 10);
        roundTrip(-1, -64, -1);
        roundTrip(-30000000, -64, 29999999);
        roundTrip(29999999, 319, -30000000);
        distinct();

        if (failures > 0) {
            System.out.println("tile packing FAIL  (" + failures + " failing)");
            System.exit(1);
        }
        System.out.println("tile packing PASS");
    }

    private static void roundTrip(int x, int y, int z) {
        List<Integer> back = TileMirror.unpack(TileMirror.pack(x, y, z));
        boolean ok = back.get(0) == x && back.get(1) == y && back.get(2) == z;
        report("round trip " + x + "," + y + "," + z + " -> " + back, ok);
    }

    private static void distinct() {
        long a = TileMirror.pack(1, 64, 10);
        long b = TileMirror.pack(1, 64, 11);
        long c = TileMirror.pack(1, 65, 10);
        long d = TileMirror.pack(2, 64, 10);
        boolean ok = a != b && a != c && a != d && b != c && b != d && c != d;
        report("neighbouring positions do not collide", ok);
    }

    private static void report(String label, boolean ok) {
        if (ok) {
            System.out.println("ok    " + label);
        } else {
            System.out.println("FAIL  " + label);
            failures++;
        }
    }
}
