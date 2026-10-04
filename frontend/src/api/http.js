import axios from "axios";

const http = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL,
  headers: {
    Accept: "application/json",
  },
  withCredentials: true,
  withXSRFToken: true,
});

export default http;
