import { Head } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';

export default function Forbidden() {
    return (
        <>
            <Head title="Forbidden" />
            <div className="mx-auto flex min-h-[70vh] max-w-3xl items-center justify-center px-6">
                <Card className="w-full border-white/5 bg-white/5">
                    <CardHeader>
                        <CardTitle className="text-2xl font-extrabold tracking-tight">403 — Forbidden</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-6">
                        <div className="text-sm text-muted-foreground/80">
                            You don’t have permission to access this page.
                        </div>
                        <div className="flex gap-3">
                            <Button asChild className="rounded-xl h-11 px-5">
                                <a href="/dashboard">Back to dashboard</a>
                            </Button>
                            <Button asChild variant="secondary" className="rounded-xl h-11 px-5 border border-white/5 bg-white/5 hover:bg-white/10">
                                <a href="javascript:history.back()">Go back</a>
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

