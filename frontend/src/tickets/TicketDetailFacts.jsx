import TicketBadge from "./TicketBadge";
import { formatTicketDate } from "./ticketUi";

export default function TicketDetailFacts({ ticket }) {
  return (
    <section className="ticket-panel ticket-detail-facts" aria-labelledby="ticket-information-title">
      <div className="ticket-detail-heading">
        <div>
          <p className="ticket-detail-reference">{ticket.reference}</p>
          <h2 id="ticket-information-title">{ticket.title}</h2>
        </div>
        <div className="ticket-detail-badges">
          <TicketBadge value={ticket.status} kind="status" />
          <TicketBadge value={ticket.priority} kind="priority" />
        </div>
      </div>
      <p className="ticket-detail-description">{ticket.description}</p>
      <dl className="ticket-detail-properties">
        <div><dt>Catégorie</dt><dd>{ticket.category?.name ?? "Indisponible"}</dd></div>
        <div><dt>Demandeur</dt><dd>{ticket.requester?.name ?? "Indisponible"}</dd></div>
        <div><dt>Technicien</dt><dd>{ticket.technician?.name ?? "Non assigné"}</dd></div>
        <div><dt>Créé le</dt><dd>{formatTicketDate(ticket.created_at)}</dd></div>
        <div><dt>Mis à jour le</dt><dd>{formatTicketDate(ticket.updated_at)}</dd></div>
        <div><dt>Résolu le</dt><dd>{ticket.resolved_at ? formatTicketDate(ticket.resolved_at) : "Pas encore résolu"}</dd></div>
        <div><dt>Clôturé le</dt><dd>{ticket.closed_at ? formatTicketDate(ticket.closed_at) : "Pas encore clôturé"}</dd></div>
      </dl>
      {ticket.resolution_note && (
        <div className="ticket-detail-resolution">
          <h3>Note de résolution</h3>
          <p>{ticket.resolution_note}</p>
        </div>
      )}
    </section>
  );
}
