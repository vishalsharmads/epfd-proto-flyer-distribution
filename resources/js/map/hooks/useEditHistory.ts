import type { LngLat } from '@/types';
import { useCallback, useEffect, useRef, useState } from 'react';

const LIMIT = 100;

/**
 * Undo / redo for the pins of the area being edited. Each finished change (a pin dragged, added or removed,
 * a coordinate typed) is one step. The history starts empty whenever a different area is opened.
 */
export function useEditHistory(areaKey: string | null) {
    const past = useRef<LngLat[][]>([]);
    const future = useRef<LngLat[][]>([]);
    const [, bump] = useState(0);

    useEffect(() => {
        past.current = [];
        future.current = [];
        bump((n) => n + 1);
    }, [areaKey]);

    /** Remember `before` as the state to return to, and forget anything that was undone. */
    const record = useCallback((before: LngLat[]) => {
        past.current = [...past.current.slice(-(LIMIT - 1)), before];
        future.current = [];
        bump((n) => n + 1);
    }, []);

    const undo = useCallback((current: LngLat[]): LngLat[] | null => {
        const prev = past.current.at(-1);
        if (!prev) return null;
        past.current = past.current.slice(0, -1);
        future.current = [...future.current, current];
        bump((n) => n + 1);
        return prev;
    }, []);

    const redo = useCallback((current: LngLat[]): LngLat[] | null => {
        const next = future.current.at(-1);
        if (!next) return null;
        future.current = future.current.slice(0, -1);
        past.current = [...past.current, current];
        bump((n) => n + 1);
        return next;
    }, []);

    return { record, undo, redo, canUndo: past.current.length > 0, canRedo: future.current.length > 0 };
}
