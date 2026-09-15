import type { ReactElement } from 'react';
import type {
    GanttBar,
    PlanningBackQuery,
    PlanningPagePermissions,
} from '../types';
import { DraggableAssignmentBar } from './draggable-assignment-bar';
import { ReadOnlyAssignmentBar } from './read-only-assignment-bar';

type Props = {
    bar: GanttBar;
    style: React.CSSProperties;
    highlighted: boolean;
    can: PlanningPagePermissions;
    planningBackQuery?: PlanningBackQuery | null;
    rangeFrom: Date;
    rangeTo: Date;
    onEdit?: (bar: GanttBar) => void;
    onDelete?: (bar: GanttBar) => void;
};

export function PlanningGanttBar({
    bar,
    style,
    highlighted,
    can,
    planningBackQuery = null,
    rangeFrom,
    rangeTo,
    onEdit,
    onDelete,
}: Props): ReactElement {
    if (can.update && !bar.is_assigned) {
        return (
            <DraggableAssignmentBar
                bar={bar}
                style={style}
                highlighted={highlighted}
                can={can}
                planningBackQuery={planningBackQuery}
                rangeFrom={rangeFrom}
                rangeTo={rangeTo}
                onEdit={onEdit}
                onDelete={onDelete}
            />
        );
    }

    return (
        <ReadOnlyAssignmentBar
            bar={bar}
            style={style}
            highlighted={highlighted}
            can={can}
            planningBackQuery={planningBackQuery}
            onEdit={onEdit}
            onDelete={onDelete}
        />
    );
}
