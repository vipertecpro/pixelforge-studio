import { onBeforeUnmount, ref, watch } from 'vue';
import { estimate as estimateRoute } from '@/routes/generations';
import type { Estimate, EstimateInput } from '@/types';

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Asks the server what the current form would cost, shortly after the user stops
 * changing it. The server does all the arithmetic, so the browser never works
 * with money itself and the shown price is the one that will be saved.
 */
export function useEstimate(input: () => EstimateInput) {
    const estimate = ref<Estimate | null>(null);
    const loading = ref(true);
    const error = ref<string | null>(null);

    let timer: ReturnType<typeof setTimeout> | undefined;
    let controller: AbortController | undefined;

    async function load() {
        controller?.abort();
        const current = new AbortController();
        controller = current;
        loading.value = true;

        try {
            const response = await fetch(estimateRoute.url(), {
                method: 'POST',
                signal: current.signal,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify(input()),
            });
            const body = await response.json();

            if (!response.ok) {
                estimate.value = null;
                error.value =
                    body.message ?? 'The price could not be worked out.';
            } else {
                estimate.value = body as Estimate;
                error.value = null;
            }
        } catch (exception) {
            if ((exception as Error).name === 'AbortError') {
                return;
            }

            estimate.value = null;
            error.value =
                'The price could not be worked out. Check your connection.';
        }

        loading.value = false;
    }

    watch(
        input,
        () => {
            clearTimeout(timer);
            timer = setTimeout(load, 300);
        },
        { deep: true, immediate: true },
    );

    onBeforeUnmount(() => {
        clearTimeout(timer);
        controller?.abort();
    });

    return { estimate, loading, error };
}
