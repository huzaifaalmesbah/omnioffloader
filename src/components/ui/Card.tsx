import { DocsLink } from './DocsLink';

interface CardProps {
	title?: string;
	description?: string;
	/** Docs page for the card, linked from its head. */
	docs?: string;
	children?: React.ReactNode;
	className?: string;
}

export function Card( {
	title,
	description,
	docs,
	children,
	className,
}: CardProps ) {
	return (
		<section
			className={ 'smowp-card' + ( className ? ` ${ className }` : '' ) }
		>
			{ ( title || description ) && (
				<div className="smowp-card__head">
					{ title && (
						<div className="smowp-card__title">
							<h2>{ title }</h2>
							{ docs && <DocsLink path={ docs } /> }
						</div>
					) }
					{ description && <p>{ description }</p> }
				</div>
			) }
			{ children }
		</section>
	);
}
