package cn.mukkit.nukkit;

import cn.nukkit.entity.Entity;
import cn.nukkit.potion.Effect;

import java.util.ArrayList;
import java.util.Comparator;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * Converts between Nukkit's effect map and the wire's effect tuples.
 *
 * <p>The wire uses `[id, duration, amplifier, visible, ambient]`, the same shape for players and
 * entities: an effect is an effect whichever body it sits on. Ids are Bedrock's numeric ids,
 * which both sides speak; the names are not stable across versions and the classes are not
 * shared, so a number is the only thing that can travel.</p>
 *
 * <p>Duration is ticks. PocketMine can mark an effect infinite and Nukkit cannot, so the mirror
 * uses `Integer.MAX_VALUE` for "forever" in both directions. A full list is sent whenever
 * anything changes, because the alternative - add/remove deltas - cannot express "this effect
 * expired and an identical one started in the same second".</p>
 */
final class EffectState {

    /**
     * Nukkit has no infinite flag; the client accepts a duration this large as "effectively
     * forever" and the server will not reach it in any realistic uptime.
     */
    static final int INFINITE_DURATION = Integer.MAX_VALUE;

    private EffectState() {
    }

    /** The effects on an entity, ordered by id so two snapshots compare with `equals`. */
    static List<List<Object>> snapshot(Entity entity) {
        List<List<Object>> rows = new ArrayList<>();
        for (Effect effect : entity.getEffects().values()) {
            rows.add(List.<Object>of(
                    effect.getId(),
                    effect.getDuration(),
                    effect.getAmplifier(),
                    effect.isVisible(),
                    effect.isAmbient()));
        }
        rows.sort(Comparator.comparingInt(row -> (Integer) row.get(0)));
        return rows;
    }

    /**
     * Applies a whole effect list and reports what could not be applied.
     *
     * <p>Removal comes first and replacement removes before adding: Nukkit's `addEffect()`
     * refuses a weaker or shorter effect over an existing one, so a mirror that only called
     * `addEffect()` would silently keep the old amplifier or duration.</p>
     */
    static List<String> apply(Entity entity, List<?> rows) {
        List<String> gaps = new ArrayList<>();
        Map<Integer, List<?>> wanted = new LinkedHashMap<>();
        for (Object raw : rows) {
            if (!(raw instanceof List<?> row) || row.size() < 2) {
                gaps.add("malformed effect row");
                continue;
            }
            wanted.put(number(row.get(0)).intValue(), row);
        }

        for (Integer id : new ArrayList<>(entity.getEffects().keySet())) {
            if (wanted.containsKey(id)) {
                continue;
            }
            entity.removeEffect(id);
            if (entity.hasEffect(id)) {
                gaps.add("effect " + id + " removal was cancelled");
            }
        }

        for (Map.Entry<Integer, List<?>> entry : wanted.entrySet()) {
            int id = entry.getKey();
            List<?> row = entry.getValue();
            Effect prototype = Effect.getEffect(id);
            if (prototype == null) {
                gaps.add("unknown effect id " + id);
                continue;
            }
            int rawDuration = number(row.get(1)).intValue();
            // A negative duration is how some plugins spell "forever"; zero would expire at once.
            int duration = rawDuration < 0 ? INFINITE_DURATION : rawDuration;
            int amplifier = row.size() > 2 ? Math.max(0, number(row.get(2)).intValue()) : 0;
            boolean visible = row.size() < 4 || Boolean.TRUE.equals(row.get(3));
            boolean ambient = row.size() > 4 && Boolean.TRUE.equals(row.get(4));

            Effect existing = entity.getEffect(id);
            if (existing != null
                    && existing.getDuration() == duration
                    && existing.getAmplifier() == amplifier
                    && existing.isVisible() == visible
                    && existing.isAmbient() == ambient) {
                continue;
            }
            if (existing != null) {
                boolean sameAmplifier = existing.getAmplifier() == amplifier;
                boolean sameVisible = existing.isVisible() == visible;
                boolean sameAmbient = existing.isAmbient() == ambient;
                if (sameAmplifier && sameVisible && sameAmbient) {
                    // Only the countdown moved. Updating the field keeps the client's own timer
                    // and avoids remove/add churn (and the events that come with it) every second.
                    existing.setDuration(duration);
                    continue;
                }
                entity.removeEffect(id);
                if (entity.hasEffect(id)) {
                    gaps.add("effect " + id + " could not be replaced");
                    continue;
                }
            }
            entity.addEffect(prototype
                    .setDuration(duration)
                    .setAmplifier(amplifier)
                    .setVisible(visible)
                    .setAmbient(ambient));
            if (!entity.hasEffect(id)) {
                gaps.add("effect " + id + " was not accepted");
            }
        }
        return gaps;
    }

    /** JSON numbers arrive as Integer, Long or Double depending on how they were written. */
    private static Number number(Object value) {
        return value instanceof Number n ? n : 0;
    }
}
