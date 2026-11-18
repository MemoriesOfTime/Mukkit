package cn.mukkit.nukkit;

import java.util.Locale;
import java.util.function.Predicate;

/**
 * Decides which command lines belong to the host - whoever typed them, a player or the console.
 *
 * <p>Nukkit answers an unknown command with "unknown command" and stops there, so a PocketMine
 * plugin's {@code /money} never gets a chance to run. The adapter has to step in front of that
 * answer: a line Nukkit has no command for is handed to the host, which has the plugin's
 * {@code CommandMap}. The reverse rule matters just as much - a command Nukkit *does* know is
 * left alone, because running it on both sides would execute it twice ({@code list} printed
 * {@code 0/20} from PocketMine and {@code 0/50} from Nukkit), and the world Nukkit owns
 * (vanilla {@code /tp}, a Nukkit plugin's own commands) is not the mirror's to overrule.</p>
 *
 * <p>The decision is a pure function so it can be tested without a server: the caller supplies
 * what Nukkit knows.</p>
 */
final class CommandRouting {

    private CommandRouting() {
    }

    /**
     * The line to forward, or {@code null} when Nukkit should keep the command.
     *
     * @param eventMessage the line as the event carried it ({@code PlayerCommandPreprocessEvent}:
     *                     with or without the leading slash; {@code ServerCommandEvent}: as typed),
     *                     surrounding blanks allowed
     * @param knownToNukkit asked with the lowercased command name, the way both command maps key it
     */
    static String route(String eventMessage, Predicate<String> knownToNukkit) {
        if (eventMessage == null) {
            return null;
        }
        // Nukkit's v137 processor appends a space to the packet's command ("/money "), and a
        // line pasted with blanks carries them too; neither belongs in the forwarded line.
        String line = eventMessage.trim();
        if (line.startsWith("/")) {
            line = line.substring(1).trim();
        }
        if (line.isEmpty()) {
            return null;
        }
        String label = label(line);
        if (label.isEmpty()) {
            return null;
        }
        return knownToNukkit.test(label) ? null : line;
    }

    /** The command name: up to the first blank, lowercased the way both command maps key it. */
    static String label(String line) {
        int end = 0;
        while (end < line.length() && !Character.isWhitespace(line.charAt(end))) {
            end++;
        }
        return line.substring(0, end).toLowerCase(Locale.ROOT);
    }
}
