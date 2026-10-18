import { priorityLabel, statusLabel } from "./ticketUi";

export default function TicketBadge({ value, kind = "status" }) {
  return <span className={`ticket-badge ticket-badge-${value}`}>{kind === "priority" ? priorityLabel(value) : statusLabel(value)}</span>;
}
