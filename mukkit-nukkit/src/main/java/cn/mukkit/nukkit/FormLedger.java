package cn.mukkit.nukkit;

import java.util.ArrayList;
import java.util.HashMap;
import java.util.Iterator;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.UUID;

/**
 * Which open forms belong to PocketMine.
 *
 * <p>Nukkit's own form bookkeeping (`Player::formWindows`) is keyed by a counter that starts at 0
 * per player and only knows about forms shown through {@code showFormWindow()}. The bridge sends
 * {@code ModalFormRequestPacket} objects directly, so a PocketMine form is invisible to that map —
 * which is exactly why the response would otherwise be dropped. Rather than registering a fake
 * window (Nukkit would then parse the answer into its own model and hand back a shape PocketMine
 * never asked for), the adapter remembers the ids it sent and intercepts the raw response first.
 *
 * <p>That only works if "an id we sent" and "an id a Nukkit plugin sent" cannot be the same
 * number; {@code BridgedPlayer} keeps its ids above {@code FORM_ID_BASE}, so the two ranges are
 * disjoint. This ledger is the Nukkit half of that agreement.
 *
 * <p>Package-private and free of Nukkit types so the eviction and isolation rules can be checked
 * without a server.
 */
final class FormLedger {

    /**
     * Per-player ceiling.
     *
     * <p>A healthy client has one form on screen; a plugin that queues more than this is not
     * something to remember forever. Dropping the oldest only costs that form's answer — the
     * host reports the late response as unexpected and the plugin's "player closed it" path runs.
     */
    static final int MAX_PENDING = 32;

    private final Map<UUID, Set<Integer>> pending = new HashMap<>();

    /** Remembers a form id as PocketMine's, evicting the oldest when the player has too many. */
    void remember(UUID player, int formId) {
        Set<Integer> ids = pending.computeIfAbsent(player, id -> new LinkedHashSet<>());
        // Re-inserting keeps "oldest" meaning "least recently (re)sent": a duplicate is a bug on
        // one side or the other, and treating the retry as the freshest entry is the safer guess.
        ids.remove(formId);
        while (ids.size() >= MAX_PENDING) {
            Iterator<Integer> it = ids.iterator();
            it.next();
            it.remove();
        }
        ids.add(formId);
    }

    /**
     * Claims the response, returning whether it was ours.
     *
     * <p>Removing on claim is what makes a form resolve exactly once: a client that sends the same
     * response twice must not run the plugin's callback twice.
     */
    boolean take(UUID player, int formId) {
        Set<Integer> ids = pending.get(player);
        if (ids == null || !ids.remove(formId)) {
            return false;
        }
        if (ids.isEmpty()) {
            pending.remove(player);
        }
        return true;
    }

    /** Drops everything for a player who left; their forms can no longer be answered. */
    int forget(UUID player) {
        Set<Integer> ids = pending.remove(player);
        return ids == null ? 0 : ids.size();
    }

    /** Drops every pending form; used when the host restarts and forgets what it had open. */
    int clear() {
        int total = size();
        pending.clear();
        return total;
    }

    int size() {
        int total = 0;
        for (Set<Integer> ids : pending.values()) {
            total += ids.size();
        }
        return total;
    }

    List<Integer> pendingFor(UUID player) {
        Set<Integer> ids = pending.get(player);
        return ids == null ? List.of() : new ArrayList<>(ids);
    }
}
