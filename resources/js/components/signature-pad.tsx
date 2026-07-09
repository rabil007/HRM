import { useEffect, useRef } from 'react';
import { Button } from '@/components/ui/button';
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

    useEffect(() => {
        const canvas = canvasRef.current;

        if (!canvas) {
            return;
        }

        const context = canvas.getContext('2d');

        if (!context) {
            return;
        }

        const resize = () => {
            const rect = canvas.getBoundingClientRect();
            canvas.width = rect.width * window.devicePixelRatio;
            canvas.height = rect.height * window.devicePixelRatio;
            context.setTransform(1, 0, 0, 1, 0, 0);
            context.scale(window.devicePixelRatio, window.devicePixelRatio);
            context.lineCap = 'round';
            context.lineJoin = 'round';
            context.lineWidth = lineWidth;
            context.strokeStyle = '#111827';
        };

        resize();
        window.addEventListener('resize', resize);

        return () => window.removeEventListener('resize', resize);
    }, [lineWidth]);

    const getPoint = (
        event: React.MouseEvent<HTMLCanvasElement> | React.TouchEvent<HTMLCanvasElement>,
    ) => {
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
        event: React.MouseEvent<HTMLCanvasElement> | React.TouchEvent<HTMLCanvasElement>,
    ) => {
        const canvas = canvasRef.current;
        const context = canvas?.getContext('2d');
        const point = getPoint(event);

        if (!canvas || !context || !point) {
            return;
        }

        drawingRef.current = true;
        context.beginPath();
        context.moveTo(point.x, point.y);
        event.preventDefault();
    };

    const draw = (
        event: React.MouseEvent<HTMLCanvasElement> | React.TouchEvent<HTMLCanvasElement>,
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

        context.lineTo(point.x, point.y);
        context.stroke();
        event.preventDefault();
    };

    const stopDrawing = () => {
        if (!drawingRef.current) {
            return;
        }

        drawingRef.current = false;
        const canvas = canvasRef.current;

        if (!canvas) {
            return;
        }

        onChange(canvas.toDataURL('image/png'));
    };

    const clear = () => {
        const canvas = canvasRef.current;
        const context = canvas?.getContext('2d');

        if (!canvas || !context) {
            return;
        }

        context.clearRect(0, 0, canvas.width, canvas.height);
        onChange(null);
    };

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
                />
            </div>
            {hideClear || fill ? (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={clear}
                    className="sr-only"
                >
                    Clear signature
                </Button>
            ) : (
                <Button type="button" variant="outline" size="sm" onClick={clear}>
                    Clear signature
                </Button>
            )}
        </div>
    );
}
