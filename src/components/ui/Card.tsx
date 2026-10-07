import { DocsLink } from './DocsLink';

interface CardProps {
	title?: string;
	description?: string;
	/** Docs page for the card, linked from its head. */
	docs?: string;
	children?: React.ReactNode;
	className?: string;
	/** Element id, so a link can bring the card into view. */
	id?: string;
}

export function Card( {
	title,
	description,
	docs,
	children,
	className,
	id,
}: CardProps ) {
	return (
		<section
			id={ id }
			tabIndex={ id ? -1 : undefined }
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
