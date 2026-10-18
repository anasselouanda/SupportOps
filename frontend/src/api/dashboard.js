import http from "./http";

export async function getDashboard(signal) {
  const { data } = await http.get("/api/v1/dashboard", { signal });
  return data.data;
}
