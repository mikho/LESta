export default function Heading({
    title,
    description,
    variant = 'default',
    level,
    id,
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
    /** The page title is the page's h1; a section title inside it (variant small) is an h2. */
    level?: 1 | 2;
    id?: string;
}) {
    const Title = (level ?? (variant === 'small' ? 2 : 1)) === 1 ? 'h1' : 'h2';

    return (
        <header className={variant === 'small' ? '' : 'mb-8 space-y-0.5'}>
            <Title
                id={id}
                className={
                    variant === 'small'
                        ? 'mb-0.5 text-base font-medium'
                        : 'text-xl font-semibold tracking-tight'
                }
            >
                {title}
            </Title>
            {description && (
                <p className="text-sm text-muted-foreground">{description}</p>
            )}
        </header>
    );
}
