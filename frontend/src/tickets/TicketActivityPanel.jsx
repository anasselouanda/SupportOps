import TicketPagination from "./TicketPagination";
import { formatTicketDate, priorityLabel, statusLabel } from "./ticketUi";

const eventLabels = {
  created: "Ticket créé",
  updated: "Informations modifiées",
  priority_changed: "Priorité modifiée",
  assignment_changed: "Affectation modifiée",
  status_changed: "Ticket démarré",
  resolved: "Ticket résolu",
  closed: "Ticket clôturé",
  reopened: "Ticket rouvert",
  comment_added: "Commentaire ajouté",
};

const fieldLabels = {
  title: "Titre",
  description: "Description",
  category_id: "Catégorie",
  priority: "Priorité",
  status: "Statut",
  technician_id: "Technicien",
  resolution_note: "Note de résolution",
  resolved_at: "Date de résolution",
  closed_at: "Date de clôture",
  comment_id: "Commentaire",
};

function ActivityValue({ field, value, ticket, commentIds }) {
  if (value === null || value === undefined || value === "") return <span>Aucun</span>;
  if (field === "status") return <span>{statusLabel(value)}</span>;
  if (field === "priority") return <span>{priorityLabel(value)}</span>;
  if (field.endsWith("_at")) return <span>{formatTicketDate(value)}</span>;
  if (field === "category_id") return <span>{Number(value) === ticket.category?.id ? ticket.category.name : `Catégorie n°${value}`}</span>;
  if (field === "technician_id") return <span>{Number(value) === ticket.technician?.id ? ticket.technician.name : `Technicien n°${value}`}</span>;
  if (field === "comment_id") return commentIds.includes(Number(value))
    ? <a href={`#ticket-comment-${value}`}>Commentaire n°{value}</a>
    : <span>Commentaire n°{value}</span>;
  if (typeof value !== "string" && typeof value !== "number") return <span>Valeur indisponible</span>;
  if (String(value).length > 300) return <details className="ticket-activity-long-value"><summary>Lire le texte complet</summary><p>{value}</p></details>;
  return <span className="ticket-activity-value">{value}</span>;
}

function EventChanges({ event, ticket, commentIds }) {
  const metadata = event.metadata ?? {};
  const before = metadata.before ?? {};
  const after = metadata.after ?? {};
  const historicalCreation = event.type === "created" && !metadata.before && !metadata.after;
  const fields = [...new Set(Object.keys(historicalCreation ? metadata : before).concat(Object.keys(after)))]
    .filter((field) => Object.hasOwn(fieldLabels, field));
  if (!fields.length) return null;

  return (
    <dl className="ticket-activity-changes">
      {fields.map((field) => (
        <div key={field}>
          <dt>{fieldLabels[field]}</dt>
          <dd>
            {historicalCreation ? <ActivityValue field={field} value={metadata[field]} ticket={ticket} commentIds={commentIds} /> : <>
              {Object.hasOwn(before, field) && <div><span className="ticket-muted">Avant : </span><ActivityValue field={field} value={before[field]} ticket={ticket} commentIds={commentIds} /></div>}
              {Object.hasOwn(after, field) && <div><span className="ticket-muted">Après : </span><ActivityValue field={field} value={after[field]} ticket={ticket} commentIds={commentIds} /></div>}
            </>}
          </dd>
        </div>
      ))}
    </dl>
  );
}

export default function TicketActivityPanel({ kind, resource, page, onPageChange, ticket, busy, commentIds = [], children }) {
  const comments = kind === "comments";
  const title = comments ? "Commentaires" : "Historique du ticket";
  const headingId = comments ? "ticket-comments-title" : "ticket-events-title";
  const currentPage = resource.result?.meta?.current_page === page;

  return (
    <section className="ticket-panel ticket-activity-panel" aria-labelledby={headingId} aria-busy={resource.pending}>
      <div className="ticket-detail-heading">
        <h2 id={headingId}>{title}</h2>
        <button className="ticket-button" type="button" onClick={resource.load} disabled={busy || resource.pending}>Actualiser {comments ? "les commentaires" : "l’historique"}</button>
      </div>
      {children}
      {resource.pending && <p className="ticket-muted" role="status">Chargement {comments ? "des commentaires" : "de l’historique"}…</p>}
      {resource.error && <div className="ticket-alert" role="alert"><p>{resource.error.message}</p><button className="ticket-button" type="button" onClick={resource.load} disabled={busy || resource.pending}>Réessayer</button></div>}
      {currentPage && resource.result.data.length > 0 ? (
        <ol className="ticket-activity-list">
          {resource.result.data.map((item) => (
            <li key={item.id} id={comments ? `ticket-comment-${item.id}` : undefined}>
              <div className="ticket-activity-heading">
                <strong>{comments ? item.author?.name : eventLabels[item.type] ?? "Événement du ticket"}</strong>
                <time dateTime={comments ? item.created_at : item.occurred_at}>{formatTicketDate(comments ? item.created_at : item.occurred_at)}</time>
              </div>
              {comments ? <p className="ticket-detail-description">{item.content}</p> : <>
                <p className="ticket-muted">Par {item.actor?.name ?? "Acteur indisponible"}</p>
                <EventChanges event={item} ticket={ticket} commentIds={commentIds} />
              </>}
            </li>
          ))}
        </ol>
      ) : !resource.pending && !resource.error && <p className="ticket-empty">{comments ? "Aucun commentaire pour le moment." : "Aucun événement à afficher."}</p>}
      {currentPage && <TicketPagination meta={resource.result.meta} onPageChange={onPageChange} pending={resource.pending || busy} label={`Pagination ${comments ? "des commentaires" : "de l’historique"}`} />}
    </section>
  );
}
