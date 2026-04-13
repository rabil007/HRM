import {
    Building2,
    Edit2,
    ExternalLink,
    Globe,
    Mail,
    MapPin,
    MoreVertical,
    Trash2,
    Users,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { Company } from '../types';

export function CompanyCard({
    company,
    onEdit,
    onDelete,
}: {
    company: Company;
    onEdit: (company: Company) => void;
    onDelete: (company: Company) => void;
}) {
    return (
        <Card className="group border-white/5 bg-white/5 backdrop-blur-xl hover:bg-white/10 transition-all duration-300 overflow-hidden relative">
            <div className="absolute top-0 right-0 p-4 opacity-0 group-hover:opacity-100 transition-opacity">
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="h-8 w-8 rounded-lg">
                            <MoreVertical className="h-4 w-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        align="end"
                        className="w-48 border-white/10 bg-black/90 backdrop-blur-xl p-1"
                    >
                        <DropdownMenuItem asChild className="focus:bg-white/10 cursor-pointer gap-2 rounded-md">
                            <a href={`/organization/companies/${company.id}`} className="flex items-center gap-2">
                                <Building2 className="h-4 w-4" />
                                View Details
                            </a>
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            onClick={() => onEdit(company)}
                            className="focus:bg-white/10 cursor-pointer gap-2 rounded-md"
                        >
                            <Edit2 className="h-4 w-4" />
                            Edit Details
                        </DropdownMenuItem>
                        <DropdownSeparator className="bg-white/10 my-1" />
                        <DropdownMenuItem
                            onClick={() => onDelete(company)}
                            className="focus:bg-destructive/10 text-destructive focus:text-destructive cursor-pointer gap-2 rounded-md"
                        >
                            <Trash2 className="h-4 w-4" />
                            Delete Company
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <CardHeader className="pb-4">
                <div className="flex items-center gap-4 mb-2">
                    <div className="h-12 w-12 rounded-2xl bg-primary/10 flex items-center justify-center border border-primary/20 text-primary group-hover:scale-110 transition-transform duration-500">
                        {company.logo_url ? (
                            <img
                                src={company.logo_url}
                                alt={company.name}
                                className="h-12 w-12 rounded-2xl object-cover"
                            />
                        ) : (
                            <Building2 className="h-6 w-6" />
                        )}
                    </div>
                    <div>
                        <Badge
                            variant="secondary"
                            className="mb-1 bg-white/5 text-muted-foreground border-white/10 text-[10px] uppercase font-bold tracking-wider"
                        >
                            {company.currency.code ?? '—'}
                        </Badge>
                        <CardTitle className="text-xl font-bold tracking-tight line-clamp-1">
                            {company.name}
                        </CardTitle>
                    </div>
                </div>
                <CardDescription className="text-sm font-medium flex items-center gap-1.5">
                    <MapPin className="h-3 w-3" />
                    {[company.city, company.country.code].filter(Boolean).join(', ') || '—'}
                    <span className="mx-1">•</span>
                    {company.industry ?? '—'}
                </CardDescription>
            </CardHeader>

            <CardContent className="space-y-4 pt-0">
                <div className="grid grid-cols-2 gap-3 mb-6">
                    <div className="p-3 rounded-xl bg-white/5 border border-white/5 text-center">
                        <div className="text-xs font-medium text-muted-foreground uppercase tracking-widest mb-1">
                            ID
                        </div>
                        <div className="text-lg font-bold">#00{company.id}</div>
                    </div>
                    <div className="p-3 rounded-xl bg-white/5 border border-white/5 text-center">
                        <div className="text-xs font-medium text-muted-foreground uppercase tracking-widest mb-1">
                            Currency
                        </div>
                        <div className="text-lg font-bold flex items-center justify-center gap-1.5">
                            <Users className="h-4 w-4 text-primary" />
                            {company.currency.code ?? '—'}
                        </div>
                    </div>
                </div>

                <div className="space-y-2.5">
                    {company.website ? (
                        <a
                            href={
                                company.website.startsWith('http')
                                    ? company.website
                                    : `https://${company.website}`
                            }
                            target="_blank"
                            rel="noreferrer noopener"
                            className="flex items-center gap-3 text-xs font-medium text-muted-foreground hover:text-primary transition-colors py-2 px-3 rounded-lg hover:bg-primary/5 border border-transparent hover:border-primary/20"
                        >
                            <Globe className="h-4 w-4" />
                            {company.website}
                            <ExternalLink className="h-3 w-3 ml-auto opacity-50" />
                        </a>
                    ) : null}
                    {company.email ? (
                        <a
                            href={`mailto:${company.email}`}
                            className="flex items-center gap-3 text-xs font-medium text-muted-foreground hover:text-primary transition-colors py-2 px-3 rounded-lg hover:bg-primary/5 border border-transparent hover:border-primary/20"
                        >
                            <Mail className="h-4 w-4" />
                            {company.email}
                        </a>
                    ) : null}
                </div>
            </CardContent>
        </Card>
    );
}

function DropdownSeparator({ className }: { className?: string }) {
    return <div className={`h-px ${className}`} />;
}

