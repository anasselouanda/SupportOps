import http from "./http";

const endpoint = "/api/v1/admin/users";

export async function listUsers(page = 1, signal) {
  const { data } = await http.get(endpoint, { params: { page, per_page: 15 }, signal });
  return data;
}

export async function createUser(form) {
  await http.get("/sanctum/csrf-cookie");
  const { data } = await http.post(endpoint, form);
  return data.user;
}

export async function updateUser(id, form) {
  await http.get("/sanctum/csrf-cookie");
  const { data } = await http.patch(`${endpoint}/${id}`, form);
  return data.user;
}
