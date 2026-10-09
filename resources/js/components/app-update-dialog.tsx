import { useEffect, useState } from 'react';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { appRefreshController } from '@/lib/app-refresh/app-refresh-controller';

export function AppUpdateDialog() {
    const [open, setOpen] = useState(false);

    useEffect(() => appRefreshController.subscribeDialog(setOpen), []);

    return (
        <AlertDialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    appRefreshController.dismissUpdate();
                }
            }}
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Update Available</AlertDialogTitle>
                    <AlertDialogDescription>
                        A new version of OMS-HRM is available with the latest
                        improvements and fixes. Reload the application to get
                        the newest version.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel
                        onClick={() => appRefreshController.dismissUpdate()}
                    >
                        Later
                    </AlertDialogCancel>
                    <AlertDialogAction
                        onClick={() => {
                            void appRefreshController.confirmReload();
                        }}
                    >
                        Reload Now
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
