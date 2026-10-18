export default function TicketPagination({ meta, onPageChange, pending = false, label = "Pagination" }) {
  if (!meta) return null;
  return <nav className="ticket-pagination" aria-label={label}>
    <button className="ticket-button" type="button" disabled={pending || meta.current_page <= 1} onClick={() => onPageChange(meta.current_page - 1)}>Précédent</button>
    <span>Page {meta.current_page} sur {meta.last_page} · {meta.total} résultat{meta.total > 1 ? "s" : ""}</span>
    <button className="ticket-button" type="button" disabled={pending || meta.current_page >= meta.last_page} onClick={() => onPageChange(meta.current_page + 1)}>Suivant</button>
  </nav>;
}
