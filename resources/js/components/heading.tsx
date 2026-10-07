export default function Heading({
    title,
    description,
    variant = 'default',
    level = 2,
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
    level?: 1 | 2;
}) {
    const Title = level === 1 ? 'h1' : 'h2';

    return (
        <header className={variant === 'small' ? '' : 'mb-8 space-y-0.5'}>
            <Title
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
