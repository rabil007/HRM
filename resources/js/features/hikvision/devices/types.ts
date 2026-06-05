export type HikvisionDeviceDetail = {
    baseInfo?: Record<string, unknown>;
    cameraChannel?: Array<Record<string, unknown>>;
    alarmInputChannel?: Array<Record<string, unknown>>;
    alarmOutputChannel?: Array<Record<string, unknown>>;
    doorChannel?: Array<Record<string, unknown>>;
    onlineStatus?: number;
} | null;

export type HikvisionDevice = {
    id: number;
    hikvision_id: string;
    serial_no: string;
    name: string | null;
    category: string | null;
    type: string | null;
    online_status: number | null;
    synced_at: string | null;
    detail: HikvisionDeviceDetail;
};
