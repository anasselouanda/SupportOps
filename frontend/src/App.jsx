import { useEffect, useState } from "react";
import http from "./api/http";

function App() {
  const [apiStatus, setApiStatus] = useState("Vérification de l’API...");

  useEffect(() => {
    http
      .get("/api/health")
      .then(({ data }) => setApiStatus(data.message))
      .catch(() => setApiStatus("API Laravel indisponible"));
  }, []);

  return (
    <main>
      <h1>SupportOps</h1>
      <p>Gestion des tickets de support informatique</p>
      <p>État du backend : {apiStatus}</p>
    </main>
  );
}

export default App;
