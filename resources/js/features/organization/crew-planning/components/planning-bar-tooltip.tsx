import type { ReactElement } from 'react';
import type { GanttBar, PlanningPagePermissions } from '../types';
import { DraggableAssignmentBar } from './draggable-assignment-bar';
import { ReadOnlyAssignmentBar } from './read-only-assignment-bar';

type Props = {
    bar: GanttBar;
    style: React.CSSProperties;
    highlighted: boolean;
    can: PlanningPagePermissions;
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
    rangeFrom,
    rangeTo,
    onEdit,
    onDelete,
}: Props): ReactElement {
    if (can.update) {
        return (
            <DraggableAssignmentBar
                bar={bar}
                style={style}
                highlighted={highlighted}
                can={can}
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
            onEdit={onEdit}
            onDelete={onDelete}
        />
    );
}
