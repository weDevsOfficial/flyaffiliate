/**
 * The asterisk after a required field's label, read by nobody: the form says
 * which fields are required in words.
 */
export default function Required() {
	return (
		<span className="text-destructive" aria-hidden="true">
			*
		</span>
	);
}
