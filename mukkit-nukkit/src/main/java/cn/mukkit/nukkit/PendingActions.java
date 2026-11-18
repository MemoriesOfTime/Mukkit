package cn.mukkit.nukkit;

import java.util.ArrayList;
import java.util.Iterator;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.function.Predicate;

/**
 * Actions the adapter is holding until the plugin host answers.
 *
 * <p>Nukkit decides a block action synchronously, the bridge answers one server tick later, so the
 * only way to let PocketMine veto something is to stop it first and replay it afterwards. This is
 * the bookkeeping for that: an id the host echoes back, and a deadline so a host that dies mid-tick
 * does not leave a player unable to break anything.</p>
 *
 * <p>Package-private and free of Nukkit types so the expiry rules can be checked without a server.</p>
 */
final class PendingActions<T> {

    record Entry<T>(long id, T payload, long deadline) {
    }

    private final Map<Long, Entry<T>> pending = new LinkedHashMap<>();
    private final int timeoutTicks;
    private long nextId = 1;
    private long tick;

    PendingActions(int timeoutTicks) {
        this.timeoutTicks = Math.max(1, timeoutTicks);
    }

    long add(T payload) {
        long id = nextId++;
        pending.put(id, new Entry<>(id, payload, tick + timeoutTicks));
        return id;
    }

    /** Removes and returns the payload, or {@code null} if the id is unknown or already expired. */
    T take(long id) {
        Entry<T> entry = pending.remove(id);
        return entry == null ? null : entry.payload();
    }

    /**
     * Advances the clock by one tick and returns everything that timed out.
     *
     * <p>Expired actions are simply dropped: the caller must not perform them. A block that stays
     * unbroken is a visible, reportable failure; a block broken without a verdict is a silent one.</p>
     */
    List<T> advance() {
        tick++;
        if (pending.isEmpty()) {
            return List.of();
        }
        List<T> expired = new ArrayList<>();
        for (Iterator<Entry<T>> it = pending.values().iterator(); it.hasNext(); ) {
            Entry<T> entry = it.next();
            if (entry.deadline() <= tick) {
                it.remove();
                expired.add(entry.payload());
            }
        }
        return expired;
    }

    List<T> clear() {
        List<T> dropped = new ArrayList<>(pending.size());
        for (Entry<T> entry : pending.values()) {
            dropped.add(entry.payload());
        }
        pending.clear();
        return dropped;
    }

    /**
     * Drops the entries a predicate matches and returns how many went.
     *
     * <p>This is what a withdrawn capability needs: the actions nobody will judge any more must not
     * sit here until their deadline, while the actions somebody still listens for have to stay.
     * Clearing the whole table would punish the second listener for the first one leaving.</p>
     */
    int removeIf(Predicate<T> predicate) {
        int dropped = 0;
        for (Iterator<Entry<T>> it = pending.values().iterator(); it.hasNext(); ) {
            if (predicate.test(it.next().payload())) {
                it.remove();
                dropped++;
            }
        }
        return dropped;
    }

    int size() {
        return pending.size();
    }
}
