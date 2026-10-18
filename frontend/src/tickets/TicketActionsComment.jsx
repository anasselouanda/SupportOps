import { useState } from "react";
import { addTicketComment } from "../api/tickets";
import TicketField from "./TicketField";

export default function TicketActionsComment({ ticketId, disabled, error, onMutation }) {
  const [content, setContent] = useState("");
  const [localError, setLocalError] = useState(null);

  async function submit(event) {
    event.preventDefault();
    if (disabled) return;
    const cleanContent = content.trim();
    if (!cleanContent || cleanContent.length > 5000) {
      setLocalError("Le commentaire doit contenir entre 1 et 5 000 caractères.");
      return;
    }
    setLocalError(null);
    const result = await onMutation({
      key: "comment", execute: () => addTicketComment(ticketId, cleanContent),
      successMessage: "Votre commentaire a été enregistré. Il apparaît dans l’ordre chronologique des commentaires.",
    });
    if (result.confirmed) setContent("");
  }

  return (
    <form className="ticket-detail-comment-form" onSubmit={submit} noValidate>
      <TicketField name="content" label="Ajouter un commentaire" as="textarea" rows={4} value={content} onChange={(event) => { setContent(event.target.value); setLocalError(null); }} disabled={disabled} required maxLength={5000} error={localError ?? (error?.target === "comment" ? error.fields?.content : null)} hint="De 1 à 5 000 caractères. Votre nom sera associé au commentaire." />
      <button className="ticket-button ticket-button-primary" type="submit" disabled={disabled}>Publier le commentaire</button>
    </form>
  );
}
