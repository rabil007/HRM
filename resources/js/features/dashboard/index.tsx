export { DashboardContent } from './dashboard-content';
export * from './dashboard-types';
export default function DashboardFeature(props: any) {
    return <DashboardContent {...props} />;
}
