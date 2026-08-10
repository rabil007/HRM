/**
 * Compact adaptive rank-row sizing for multi-lane Gantt bars.
 * 1 lane stays near the historic 48px row; extra lanes add ~28px each.
 */
export const GANTT_ROW_PAD_Y = 6;
export const GANTT_LANE_HEIGHT = 28;
export const GANTT_LANE_GAP = 4;
export const GANTT_SINGLE_LANE_HEIGHT = 48;

export type LaneAssignable = {
    id: number;
    start: string;
    end: string;
};

export type LanedBar<T extends LaneAssignable> = {
    bar: T;
    lane: number;
};

/** Inclusive ISO date intervals overlap when they share any calendar day. */
export function dateIntervalsOverlap(
    aStart: string,
    aEnd: string,
    bStart: string,
    bEnd: string,
): boolean {
    return aStart <= bEnd && bStart <= aEnd;
}

/**
 * Assigns bars to the first lane where the date interval does not overlap
 * another bar. Sorting is deterministic: start → end → id.
 */
export function assignBarsToLanes<T extends LaneAssignable>(
    bars: readonly T[],
): LanedBar<T>[] {
    const sorted = [...bars].sort((left, right) => {
        if (left.start !== right.start) {
            return left.start < right.start ? -1 : 1;
        }

        if (left.end !== right.end) {
            return left.end < right.end ? -1 : 1;
        }

        return left.id - right.id;
    });

    const laneOccupancy: { start: string; end: string }[][] = [];
    const result: LanedBar<T>[] = [];

    for (const bar of sorted) {
        let laneIndex = -1;

        for (let index = 0; index < laneOccupancy.length; index++) {
            const overlaps = laneOccupancy[index].some((existing) =>
                dateIntervalsOverlap(
                    bar.start,
                    bar.end,
                    existing.start,
                    existing.end,
                ),
            );

            if (!overlaps) {
                laneIndex = index;
                break;
            }
        }

        if (laneIndex === -1) {
            laneIndex = laneOccupancy.length;
            laneOccupancy.push([]);
        }

        laneOccupancy[laneIndex].push({ start: bar.start, end: bar.end });
        result.push({ bar, lane: laneIndex });
    }

    return result;
}

export function laneCountForBars(bars: readonly LaneAssignable[]): number {
    if (bars.length === 0) {
        return 1;
    }

    const lanes = assignBarsToLanes(bars);
    let maxLane = 0;

    for (const entry of lanes) {
        if (entry.lane > maxLane) {
            maxLane = entry.lane;
        }
    }

    return maxLane + 1;
}

export function rowHeightForLaneCount(laneCount: number): number {
    const lanes = Math.max(1, laneCount);

    if (lanes === 1) {
        return GANTT_SINGLE_LANE_HEIGHT;
    }

    return (
        GANTT_ROW_PAD_Y * 2 +
        lanes * GANTT_LANE_HEIGHT +
        (lanes - 1) * GANTT_LANE_GAP
    );
}

/** CSS top offset for a lane within an expanded rank row. */
export function laneTopOffset(lane: number, laneCount: number): number {
    if (laneCount <= 1) {
        return GANTT_ROW_PAD_Y;
    }

    return GANTT_ROW_PAD_Y + lane * (GANTT_LANE_HEIGHT + GANTT_LANE_GAP);
}

/** Bar height within a lane (single-lane rows keep the historic ~36px bar). */
export function laneBarHeight(laneCount: number): number {
    if (laneCount <= 1) {
        return GANTT_SINGLE_LANE_HEIGHT - GANTT_ROW_PAD_Y * 2;
    }

    return GANTT_LANE_HEIGHT;
}
