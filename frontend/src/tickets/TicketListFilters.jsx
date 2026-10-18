import { useState } from "react";
import { Search, SlidersHorizontal } from "lucide-react";
import TicketField from "./TicketField";
import { PRIORITY_OPTIONS, STATUS_OPTIONS } from "./ticketUi";

export default function TicketListFilters({ filters, onChange }) {
  const [search, setSearch] = useState(filters.search);

  function submit(event) {
    event.preventDefault();
    onChange({ search });
  }

  function changeSelect(event) {
    onChange({ search, [event.target.name]: event.target.value });
  }

  return (
    <form className="ticket-list-filters" onSubmit={submit} role="search" aria-label="Rechercher et filtrer les tickets">
      <div className="ticket-list-search">
        <TicketField name="search" label="Rechercher un ticket" value={search} onChange={(event) => setSearch(event.target.value)} maxLength={255} placeholder="Titre ou référence" />
        <button className="ticket-list-button ticket-list-button-primary" type="submit"><Search size={17} aria-hidden="true" />Rechercher</button>
      </div>
      <div className="ticket-list-filter-options">
        <span className="ticket-list-filter-label"><SlidersHorizontal size={16} aria-hidden="true" />Filtres</span>
        <TicketField as="select" name="status" label="Statut" value={filters.status} onChange={changeSelect}>
          <option value="">Tous les statuts</option>
          {STATUS_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
        </TicketField>
        <TicketField as="select" name="priority" label="Priorité" value={filters.priority} onChange={changeSelect}>
          <option value="">Toutes les priorités</option>
          {PRIORITY_OPTIONS.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
        </TicketField>
        <TicketField as="select" name="per_page" label="Résultats par page" value={filters.per_page} onChange={changeSelect}>
          {[15, 25, 50, 100].includes(Number(filters.per_page)) ? null : <option value={filters.per_page}>{filters.per_page}</option>}
          {[15, 25, 50, 100].map((count) => <option key={count} value={count}>{count}</option>)}
        </TicketField>
      </div>
    </form>
  );
}
