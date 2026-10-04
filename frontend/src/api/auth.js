import http from "./http";

export async function getCurrentUser() {
  const { data } = await http.get("/api/user");
  return data.user;
}

export async function register(form) {
  await http.get("/sanctum/csrf-cookie");
  const { data } = await http.post("/api/register", form);
  return data.user;
}

export async function login(form) {
  await http.get("/sanctum/csrf-cookie");
  const { data } = await http.post("/api/login", form);
  return data.user;
}

export async function logout() {
  await http.get("/sanctum/csrf-cookie");
  await http.post("/api/logout");
}
