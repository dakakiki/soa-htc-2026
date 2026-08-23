import { computed } from 'vue';
import { useSessionStore } from '@/stores/session';

/**
 * The signed-in account's row-level scope, as the pickers need it.
 *
 * A coordinator always works inside one country, and a venue coordinator inside
 * one venue, so those fields are shown fixed rather than as a select the user
 * could search — there is nothing to choose. An admin (`schools.view.all`) has
 * nothing pinned. The server enforces the same scope regardless; this only
 * keeps the UI from offering what would come back refused or empty.
 */
export function useScope() {
    const session = useSessionStore();

    const scope = computed(() => session.user?.scope ?? null);
    const unrestricted = computed(() => scope.value?.all_schools ?? true);

    const country = computed(() => scope.value?.country ?? null);
    const countryLocked = computed(() => !unrestricted.value && country.value !== null);

    const venues = computed(() => scope.value?.schools ?? []);
    // Exactly one venue is the venue coordinator: their venue and its region are
    // a given, not a choice.
    const venueLocked = computed(() => !unrestricted.value && venues.value.length === 1);
    const venue = computed(() => (venueLocked.value ? venues.value[0] : null));
    const region = computed(() => venue.value?.region ?? null);
    const regionLocked = computed(() => venueLocked.value);

    return { unrestricted, country, countryLocked, venues, venue, venueLocked, region, regionLocked };
}
