import http from "./http";

export async function getCurrentUser(signal) {
  const { data } = await http.get("/api/user", { signal });
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
