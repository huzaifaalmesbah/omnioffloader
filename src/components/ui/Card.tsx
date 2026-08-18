interface CardProps {
	title?: string;
	description?: string;
	children?: React.ReactNode;
	className?: string;
}

export function Card( { title, description, children, className }: CardProps ) {
	return (
		<section
			className={ 'smowp-card' + ( className ? ` ${ className }` : '' ) }
		>
			{ ( title || description ) && (
				<div className="smowp-card__head">
					{ title && <h2>{ title }</h2> }
					{ description && <p>{ description }</p> }
				</div>
			) }
			{ children }
		</section>
	);
}
