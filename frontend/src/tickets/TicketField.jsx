import { useId } from "react";

export default function TicketField({ name, label, error, hint, id: providedId, as: Element = "input", children, ...props }) {
  const uniqueId = useId();
  const id = providedId ?? `ticket-${name}-${uniqueId}`;
  const descriptions = [hint && `${id}-hint`, error && `${id}-error`].filter(Boolean).join(" ");
  return (
    <div className="ticket-field">
      <label htmlFor={id}>{label}</label>
      {Element === "input"
        ? <input {...props} id={id} name={name} aria-invalid={Boolean(error)} aria-describedby={descriptions || undefined} />
        : <Element {...props} id={id} name={name} aria-invalid={Boolean(error)} aria-describedby={descriptions || undefined}>{children}</Element>}
      {hint && <p className="ticket-muted" id={`${id}-hint`}>{hint}</p>}
      {error && <p className="ticket-field-error" id={`${id}-error`} role="alert">{error}</p>}
    </div>
  );
}
