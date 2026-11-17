<script setup lang="ts">
import { Plus } from '@lucide/vue';
import ReferenceImageRow from '@/components/ReferenceImageRow.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    max: number;
    errors: Record<string, string | undefined>;
}>();

const links = defineModel<string[]>({ required: true });

function add() {
    if (links.value.length < props.max) {
        links.value = [...links.value, ''];
    }
}

function remove(position: number) {
    links.value = links.value.filter((_, at) => at !== position);
}

function update(position: number, value: string) {
    links.value = links.value.map((link, at) =>
        at === position ? value : link,
    );
}

function errorAt(position: number): string | undefined {
    return props.errors[`reference_images.${position}`];
}
</script>

<template>
    <div class="grid gap-3">
        <div>
            <Label>Reference images (optional)</Label>
            <p class="mt-1 text-xs text-muted-foreground">
                Links to images that guide the style, up to {{ max }}. Each one
                adds to the price.
            </p>
        </div>

        <ReferenceImageRow
            v-for="(link, position) in links"
            :key="position"
            :model-value="link"
            :number="position + 1"
            :error="errorAt(position)"
            @update:model-value="update(position, $event)"
            @remove="remove(position)"
        />
        <InputError :message="errors.reference_images" />

        <div>
            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="links.length >= max"
                @click="add"
            >
                <Plus /> Add a reference image
            </Button>
        </div>
    </div>
</template>
