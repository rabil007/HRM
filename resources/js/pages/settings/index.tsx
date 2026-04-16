import { Head, Link } from '@inertiajs/react';
import { 
    Shield, 
    Palette, 
    Globe2, 
    Wallet, 
    IdCard, 
    BadgeCheck, 
    Users, 
    PiggyBank, 
    FileText,
    ChevronRight,
    Settings as SettingsIcon,
    Database
} from 'lucide-react';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';

const SETTINGS_GROUPS = [
    {
        title: 'Security & Access',
        description: 'Manage your account security, password, and two-factor authentication.',
        items: [
            { title: 'Security Settings', href: '/settings/security', icon: Shield, color: 'bg-blue-500/10 text-blue-600' },
            { title: 'Appearance', href: '/settings/appearance', icon: Palette, color: 'bg-purple-500/10 text-purple-600' },
        ]
    },
    {
        title: 'Master Data Management',
        description: 'Core configuration for your organization including regional and financial data.',
        items: [
            { title: 'Countries', href: '/settings/master-data/countries', icon: Globe2, color: 'bg-emerald-500/10 text-emerald-600' },
            { title: 'Currencies', href: '/settings/master-data/currencies', icon: Wallet, color: 'bg-amber-500/10 text-amber-600' },
            { title: 'Visa Types', href: '/settings/master-data/visa-types', icon: IdCard, color: 'bg-cyan-500/10 text-cyan-600' },
            { title: 'Religions', href: '/settings/master-data/religions', icon: BadgeCheck, color: 'bg-indigo-500/10 text-indigo-600' },
            { title: 'Genders', href: '/settings/master-data/genders', icon: Users, color: 'bg-rose-500/10 text-rose-600' },
            { title: 'Banks', href: '/settings/master-data/banks', icon: PiggyBank, color: 'bg-orange-500/10 text-orange-600' },
            { title: 'Document Types', href: '/settings/master-data/document-types', icon: FileText, color: 'bg-slate-500/10 text-slate-600' },
        ]
    }
];

export default function SettingsIndex() {
    return (
        <AppLayout>
            <Head title="Global Settings" />
            
            <div className="relative min-h-[calc(100vh-80px)] overflow-hidden">
                {/* Executive Background System */}
                <div className="absolute top-0 right-0 w-[500px] h-[500px] bg-primary/5 blur-[120px] rounded-full -translate-y-1/2 translate-x-1/2 pointer-events-none" />
                <div className="absolute bottom-0 left-0 w-[500px] h-[500px] bg-blue-500/5 blur-[120px] rounded-full translate-y-1/2 -translate-x-1/2 pointer-events-none" />

                <div className="max-w-[1400px] mx-auto space-y-20 py-16 px-10 relative z-10 animate-in fade-in slide-in-from-bottom-4 duration-1000">
                    {/* Immersive Header Section */}
                    <div className="flex flex-col md:flex-row md:items-end justify-between gap-8 pb-12 border-b border-border/40">
                        <div className="space-y-6">
                            <div className="flex items-center gap-3">
                                <span className="h-2 w-2 rounded-full bg-primary animate-pulse shadow-[0_0_10px_rgba(var(--primary),0.8)]" />
                                <span className="text-[11px] font-black uppercase tracking-[0.4em] text-primary">System Command</span>
                            </div>
                            <div className="space-y-2">
                                <h1 className="text-6xl font-black tracking-tighter text-foreground leading-[0.9]">
                                    Control <br />
                                    <span className="text-primary/90">Center</span>
                                </h1>
                                <p className="text-muted-foreground/80 text-xl font-medium max-w-xl leading-relaxed">
                                    Architect your organizational structure and fine-tune global system parameters from a single high-fidelity interface.
                                </p>
                            </div>
                        </div>

                        <div className="flex flex-col items-end gap-2">
                            <div className="flex items-center gap-4 p-4 rounded-[2rem] bg-card/40 border border-border/40 backdrop-blur-md shadow-2xl">
                                <div className="h-12 w-12 rounded-2xl bg-emerald-500/10 flex items-center justify-center text-emerald-500">
                                    <Database className="h-6 w-6" />
                                </div>
                                <div className="pr-4">
                                    <p className="text-[10px] font-black uppercase tracking-widest text-muted-foreground/60 leading-none mb-1">Database Sync</p>
                                    <p className="text-sm font-black text-foreground">Cloud Active</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Settings Sections */}
                    <div className="space-y-32">
                        {SETTINGS_GROUPS.map((group, gIdx) => (
                            <div key={group.title} className="space-y-10">
                                <div className="flex flex-col gap-4 animate-in fade-in slide-in-from-left-4 duration-700">
                                    <div className="flex items-center gap-4">
                                        <span className="text-3xl font-black text-primary/20">0{gIdx + 1}</span>
                                        <h2 className="text-2xl font-black text-foreground tracking-tight">{group.title}</h2>
                                        <div className="h-px flex-1 bg-gradient-to-r from-border/60 to-transparent" />
                                    </div>
                                    <p className="text-sm text-muted-foreground font-medium max-w-2xl leading-relaxed">
                                        {group.description}
                                    </p>
                                </div>

                                <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                                    {group.items.map((item, iIdx) => (
                                        <Link
                                            key={item.title}
                                            href={item.href}
                                            className="group relative flex flex-col p-8 rounded-[3rem] border border-border/40 bg-card/20 hover:bg-card/60 hover:border-primary/30 hover:shadow-[0_40px_80px_-20px_rgba(0,0,0,0.1)] transition-all duration-700 overflow-hidden"
                                            style={{ transitionDelay: `${iIdx * 50}ms` }}
                                        >
                                            {/* Neon Glow on Hover */}
                                            <div className="absolute inset-0 bg-gradient-to-br from-primary/5 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-1000" />
                                            
                                            <div className="flex items-start justify-between mb-10 relative z-10">
                                                <div className={`h-16 w-16 rounded-[1.75rem] ${item.color} flex items-center justify-center shadow-xl group-hover:scale-110 group-hover:rotate-6 transition-all duration-700 ease-out`}>
                                                    <item.icon className="h-8 w-8" />
                                                </div>
                                                <div className="h-12 w-12 rounded-full border border-border/40 flex items-center justify-center bg-card/40 backdrop-blur-md group-hover:bg-primary group-hover:border-primary transition-all duration-500 shadow-lg group-hover:shadow-primary/40">
                                                    <ChevronRight className="h-6 w-6 text-muted-foreground group-hover:text-primary-foreground transition-all group-hover:translate-x-0.5" />
                                                </div>
                                            </div>

                                            <div className="space-y-2 relative z-10">
                                                <h3 className="text-xl font-black tracking-tight text-foreground group-hover:text-primary transition-colors">
                                                    {item.title}
                                                </h3>
                                                <p className="text-sm text-muted-foreground/80 leading-relaxed font-medium">
                                                    Configuration interface for corporate <span className="text-foreground/60">{item.title.toLowerCase()}</span> standards and validations.
                                                </p>
                                            </div>

                                            {/* Decorative Index */}
                                            <div className="absolute bottom-6 right-8 text-[40px] font-black text-foreground/[0.03] select-none group-hover:text-primary/5 transition-colors">
                                                0{iIdx + 1}
                                            </div>
                                        </Link>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>

                    {/* Integrated Support Section */}
                    <div className="relative p-12 rounded-[4rem] bg-primary/5 border border-primary/10 overflow-hidden group">
                        <div className="absolute top-0 left-0 w-full h-full bg-gradient-to-r from-primary/[0.05] to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-1000" />
                        
                        <div className="flex flex-col lg:flex-row items-center gap-12 justify-between relative z-10">
                            <div className="flex items-center gap-8">
                                <div className="h-20 w-20 rounded-[2.5rem] bg-primary/10 flex items-center justify-center text-primary shadow-inner rotate-3 group-hover:rotate-0 transition-transform duration-700">
                                    <SettingsIcon className="h-10 w-10" />
                                </div>
                                <div className="space-y-2">
                                    <h4 className="text-2xl font-black tracking-tight">Advanced System Architecture</h4>
                                    <p className="text-muted-foreground font-medium max-w-xl">
                                        Our core engine relies on these parameters to maintain cross-module integrity. Modification requires organizational oversight.
                                    </p>
                                </div>
                            </div>
                            <Button className="rounded-3xl h-16 px-10 bg-primary hover:bg-primary/90 text-primary-foreground shadow-2xl shadow-primary/20 transition-all active:scale-95 font-black text-sm uppercase tracking-widest whitespace-nowrap">
                                Request Guidance
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </Main>
    );
}
