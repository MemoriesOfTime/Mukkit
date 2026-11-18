package cn.mukkit.nukkit;

import java.util.ArrayList;
import java.util.List;
import java.util.Set;

/**
 * Offline checks for {@link CommandRouting}.
 *
 * <p>The routing decision is the one place where a mistake is invisible in testing but wrong in
 * production: forward a command Nukkit owns and it runs twice; keep one it does not and the
 * plugin's command silently does not exist. The inputs that make that hard are not exotic - a
 * trailing space from Nukkit's own processor, a leading slash, uppercase, arguments - so they are
 * all pinned here without a server.</p>
 */
public final class CommandRoutingCheck {

    private static int checks;
    private static int failures;

    public static void main(String[] args) {
        // ---------------------------------------------------------------- forwarded
        check("an unknown command is forwarded", "money".equals(route("/money", Set.of())));
        check("a line without a slash is forwarded the same way",
                "money".equals(route("money", Set.of())));
        check("Nukkit's trailing space does not reach the host",
                "money".equals(route("/money ", Set.of())));
        check("arguments travel verbatim",
                "money Steve 100".equals(route("/money Steve 100", Set.of())));
        check("leading and trailing blanks are trimmed",
                "money Steve".equals(route("  /money Steve   ", Set.of())));
        check("the player's casing is preserved for the host to resolve",
                "Money".equals(route("/Money", Set.of())));
        check("an unknown command with a leading slash inside an argument is untouched",
                "money /not-a-flag".equals(route("/money /not-a-flag", Set.of())));

        // ---------------------------------------------------------------- kept by Nukkit
        check("a command Nukkit owns is not forwarded", route("/tp 0 64 0", Set.of("tp")) == null);
        check("the lookup is asked with the lowercased name", route("/TP 0 64 0", Set.of("tp")) == null);
        check("an alias Nukkit knows is kept too", route("/gamemode 1", Set.of("gamemode", "gm")) == null);
        // Console lines arrive without the slash; `list` printing twice is how this was noticed.
        check("a console line Nukkit owns is kept", route("list", Set.of("list")) == null);
        check("the predicate is asked once, with the label",
                askedOnce("/tp 0 64 0", "tp"));

        // ---------------------------------------------------------------- nothing to route
        check("an empty message is not a command", route("", Set.of()) == null);
        check("a lone slash is not a command", route("/", Set.of()) == null);
        check("whitespace is not a command", route("   ", Set.of()) == null);
        check("a null message is not a command", route(null, Set.of()) == null);

        // ---------------------------------------------------------------- labels
        check("label stops at the first blank", "cmdprobe".equals(CommandRouting.label("cmdprobe a b")));
        check("label lowercases", "cmdprobe".equals(CommandRouting.label("CmdProbe")));
        check("label of an empty line is empty", CommandRouting.label("").isEmpty());

        System.out.println();
        if (failures > 0) {
            System.out.println("FAIL  command-routing-jvm: " + failures + " of " + checks + " failed");
            System.exit(1);
        }
        System.out.println("command-routing-jvm PASS (" + checks + " checks)");
    }

    /** Routes a line and records what the predicate was asked. */
    private static boolean askedOnce(String message, String expected) {
        List<String> asked = new ArrayList<>(1);
        String line = CommandRouting.route(message, label -> {
            asked.add(label);
            return true;
        });
        return line == null && asked.equals(List.of(expected));
    }

    private static String route(String message, Set<String> knownToNukkit) {
        return CommandRouting.route(message, knownToNukkit::contains);
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

    private CommandRoutingCheck() {
    }
}
