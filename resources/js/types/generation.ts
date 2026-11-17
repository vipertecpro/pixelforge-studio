export type GenerationStatus = 'queued' | 'processing' | 'succeeded' | 'failed';

export type Generation = {
    id: string;
    status: GenerationStatus;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    estimated_min: string;
    estimated_max: string;
    charged: string | null;
    image_url: string | null;
    image_expired: boolean;
    error: string | null;
    sending: boolean;
    created_at: string | null;
    completed_at: string | null;
};

export type Estimate = {
    min: string;
    max: string;
    provider_min: string;
    provider_max: string;
    markup_percent: number;
    tokens: {
        text: [number, number];
        image: number;
        output: number;
        reasoning: [number, number];
    };
};

export type EstimateInput = {
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
};
