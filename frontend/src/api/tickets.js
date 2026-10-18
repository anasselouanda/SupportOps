import http from "./http";

const root = "/api/v1/tickets";
const ticketPath = (id) => `${root}/${encodeURIComponent(String(id))}`;
const get = async (url, params, signal) => (await http.get(url, { params, signal })).data;

async function mutate(method, url, payload) {
  await http.get("/sanctum/csrf-cookie");
  const { data } = await http.request({ method, url, data: payload });
  return data.data;
}

export const listTickets = (params, signal) => get(root, params, signal);
export const getTicket = async (id, signal) => (await get(ticketPath(id), undefined, signal)).data;
export const listCategories = async (signal) => (await get("/api/v1/categories", undefined, signal)).data;
export const createTicket = (payload) => mutate("post", root, payload);
export const updateTicket = (id, payload) => mutate("patch", ticketPath(id), payload);
export const assignTicket = (id, technicianId) => mutate("patch", `${ticketPath(id)}/assignment`, { technician_id: technicianId });
export const changeTicketStatus = (id, payload) => mutate("patch", `${ticketPath(id)}/status`, payload);
export const addTicketComment = (id, content) => mutate("post", `${ticketPath(id)}/comments`, { content });
export const listTicketComments = (id, params, signal) => get(`${ticketPath(id)}/comments`, params, signal);
export const listTicketEvents = (id, params, signal) => get(`${ticketPath(id)}/events`, params, signal);
export const listActiveTechnicians = (params, signal) => get("/api/v1/admin/technicians", params, signal);
