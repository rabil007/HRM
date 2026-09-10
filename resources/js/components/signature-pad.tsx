import { Undo2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import type {
    SignaturePoint,
    SignatureStroke,
} from '@/features/esign/signature-strokes';
import {
    commitStroke,
    signaturePayloadFromStrokes,
    undoLastStroke,
} from '@/features/esign/signature-strokes';
import { cn } from '@/lib/utils';

export function SignaturePad({
    onChange,
    className,
    fill = false,
    canvasClassName,
    lineWidth = 2,
    hideClear = false,
}: {
    onChange: (dataUrl: string | null) => void;
    className?: string;
    fill?: boolean;
    canvasClassName?: string;
    lineWidth?: number;
    hideClear?: boolean;
}) {
    const canvasRef = useRef<HTMLCanvasElement>(null);
    const drawingRef = useRef(false);
    const strokesRef = useRef<SignatureStroke[]>([]);
    const currentStrokeRef = useRef<SignatureStroke | null>(null);
    const onChangeRef = useRef(onChange);
    const sizeRef = useRef({ width: 0, height: 0 });
    const [strokeCount, setStrokeCount] = useState(0);

    useEffect(() => {
        onChangeRef.current = onChange;
    }, [onChange]);

    useEffect(() => {
        const canvas = canvasRef.current;

        if (!canvas) {
            return;
        }

        const context = canvas.getContext('2d');

        if (!context) {
            return;
        }

        const configure = () => {
            context.setTransform(1, 0, 0, 1, 0, 0);
            context.scale(window.devicePixelRatio, window.devicePixelRatio);
            context.lineCap = 'round';
            context.lineJoin = 'round';
            context.lineWidth = lineWidth;
            context.strokeStyle = '#111827';
            context.fillStyle = '#111827';
        };

        const paintStrokes = () => {
            context.setTransform(1, 0, 0, 1, 0, 0);
            context.clearRect(0, 0, canvas.width, canvas.height);
            configure();

            for (const stroke of strokesRef.current) {
                paintStroke(context, stroke, lineWidth);
            }
        };

        const resize = () => {
            const rect = canvas.getBoundingClientRect();
            const width = Math.max(
                1,
                Math.round(rect.width * window.devicePixelRatio),
            );
            const height = Math.max(
                1,
                Math.round(rect.height * window.devicePixelRatio),
            );

            if (
                width === sizeRef.current.width &&
                height === sizeRef.current.height
            ) {
                return;
            }

            sizeRef.current = { width, height };
            canvas.width = width;
            canvas.height = height;
            paintStrokes();
        };

        resize();
        const observer = new ResizeObserver(resize);
        observer.observe(canvas);
        window.addEventListener('resize', resize);

        return () => {
            observer.disconnect();
            window.removeEventListener('resize', resize);
        };
    }, [lineWidth]);

    const emitChange = () => {
        const canvas = canvasRef.current;

        if (!canvas) {
            return;
        }

        onChangeRef.current(
            signaturePayloadFromStrokes(strokesRef.current, () =>
                canvas.toDataURL('image/png'),
            ),
        );
    };

    const paintCurrentCanvas = () => {
        const canvas = canvasRef.current;
        const context = canvas?.getContext('2d');

        if (!canvas || !context) {
            return;
        }

        context.setTransform(1, 0, 0, 1, 0, 0);
        context.clearRect(0, 0, canvas.width, canvas.height);
        context.scale(window.devicePixelRatio, window.devicePixelRatio);
        context.lineCap = 'round';
        context.lineJoin = 'round';
        context.lineWidth = lineWidth;
        context.strokeStyle = '#111827';
        context.fillStyle = '#111827';

        for (const stroke of strokesRef.current) {
            paintStroke(context, stroke, lineWidth);
        }
    };

    const getPoint = (
        event:
            | React.MouseEvent<HTMLCanvasElement>
            | React.TouchEvent<HTMLCanvasElement>,
    ): SignaturePoint | null => {
        const canvas = canvasRef.current;

        if (!canvas) {
            return null;
        }

        const rect = canvas.getBoundingClientRect();

        if ('touches' in event) {
            const touch = event.touches[0] ?? event.changedTouches[0];

            if (!touch) {
                return null;
            }

            return {
                x: touch.clientX - rect.left,
                y: touch.clientY - rect.top,
            };
        }

        return {
            x: event.clientX - rect.left,
            y: event.clientY - rect.top,
        };
    };

    const startDrawing = (
        event:
            | React.MouseEvent<HTMLCanvasElement>
            | React.TouchEvent<HTMLCanvasElement>,
    ) => {
        const canvas = canvasRef.current;
        const context = canvas?.getContext('2d');
        const point = getPoint(event);

        if (!canvas || !context || !point) {
            return;
        }

        drawingRef.current = true;
        currentStrokeRef.current = [point];
        context.beginPath();
        context.moveTo(point.x, point.y);
        event.preventDefault();
    };

    const draw = (
        event:
            | React.MouseEvent<HTMLCanvasElement>
            | React.TouchEvent<HTMLCanvasElement>,
    ) => {
        if (!drawingRef.current) {
            return;
        }

        const canvas = canvasRef.current;
        const context = canvas?.getContext('2d');
        const point = getPoint(event);

        if (!canvas || !context || !point) {
            return;
        }

        currentStrokeRef.current?.push(point);
        context.lineTo(point.x, point.y);
        context.stroke();
        event.preventDefault();
    };

    const stopDrawing = () => {
        if (!drawingRef.current) {
            return;
        }

        drawingRef.current = false;
        strokesRef.current = commitStroke(
            strokesRef.current,
            currentStrokeRef.current,
        );
        currentStrokeRef.current = null;
        setStrokeCount(strokesRef.current.length);
        paintCurrentCanvas();
        emitChange();
    };

    const undo = () => {
        if (strokesRef.current.length === 0) {
            return;
        }

        strokesRef.current = undoLastStroke(strokesRef.current);
        setStrokeCount(strokesRef.current.length);
        paintCurrentCanvas();
        emitChange();
    };

    const clear = () => {
        strokesRef.current = [];
        currentStrokeRef.current = null;
        drawingRef.current = false;
        setStrokeCount(0);
        paintCurrentCanvas();
        onChangeRef.current(null);
    };

    const showActions = !hideClear && !fill;
    const canUndo = strokeCount > 0;

    return (
        <div className={cn('space-y-2', className)}>
            <div
                className={cn(
                    'overflow-hidden rounded-lg border bg-white',
                    fill && 'h-full rounded-none border-0',
                )}
            >
                <canvas
                    ref={canvasRef}
                    className={cn(
                        'w-full touch-none',
                        fill ? 'h-full min-h-[48px]' : 'h-40',
                        canvasClassName,
                    )}
                    onMouseDown={startDrawing}
                    onMouseMove={draw}
                    onMouseUp={stopDrawing}
                    onMouseLeave={stopDrawing}
                    onTouchStart={startDrawing}
                    onTouchMove={draw}
                    onTouchEnd={stopDrawing}
                    onTouchCancel={stopDrawing}
                />
            </div>
            {showActions ? (
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={!canUndo}
                        onClick={undo}
                    >
                        <Undo2 className="size-3.5" />
                        Undo last stroke
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={!canUndo}
                        onClick={clear}
                    >
                        Clear
                    </Button>
                </div>
            ) : (
                <div className="flex gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={undo}
                        className="sr-only"
                    >
                        Undo last stroke
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={clear}
                        className="sr-only"
                    >
                        Clear signature
                    </Button>
                </div>
            )}
        </div>
    );
}

function paintStroke(
    context: CanvasRenderingContext2D,
    stroke: SignatureStroke,
    lineWidth: number,
): void {
    if (stroke.length === 0) {
        return;
    }

    if (stroke.length === 1) {
        context.beginPath();
        context.arc(
            stroke[0].x,
            stroke[0].y,
            Math.max(lineWidth / 2, 1),
            0,
            Math.PI * 2,
        );
        context.fill();

        return;
    }

    context.beginPath();
    context.moveTo(stroke[0].x, stroke[0].y);

    for (let index = 1; index < stroke.length; index++) {
        context.lineTo(stroke[index].x, stroke[index].y);
    }

    context.stroke();
}
