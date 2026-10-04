import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import * as authApi from "../api/auth";
import { AuthContext } from "./AuthContext";

function sessionErrorMessage(error) {
  if (error.response?.status === 419) {
    return "Votre session a expiré. Réessayez de vous connecter.";
  }

  if (!error.response) {
    return "Impossible de vérifier votre session. Vérifiez votre connexion et réessayez.";
  }

  return "Impossible de vérifier votre session. Réessayez dans un instant.";
}

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);
  const [sessionError, setSessionError] = useState(null);
  const requestVersion = useRef(0);

  useEffect(() => {
    let active = true;
    const version = ++requestVersion.current;

    authApi
      .getCurrentUser()
      .then((currentUser) => {
        if (active && version === requestVersion.current) {
          setUser(currentUser);
          setSessionError(null);
        }
      })
      .catch((error) => {
        if (!active || version !== requestVersion.current) return;

        if (error.response?.status === 401) {
          setUser(null);
          setSessionError(null);
        } else {
          setSessionError(sessionErrorMessage(error));
        }
      })
      .finally(() => {
        if (active && version === requestVersion.current) setLoading(false);
      });

    return () => {
      active = false;
    };
  }, []);

  const register = useCallback(async (form) => {
    const version = ++requestVersion.current;
    try {
      const nextUser = await authApi.register(form);
      if (version === requestVersion.current) {
        setUser(nextUser);
        setSessionError(null);
      }
      return nextUser;
    } finally {
      if (version === requestVersion.current) setLoading(false);
    }
  }, []);

  const login = useCallback(async (form) => {
    const version = ++requestVersion.current;
    try {
      const nextUser = await authApi.login(form);
      if (version === requestVersion.current) {
        setUser(nextUser);
        setSessionError(null);
      }
      return nextUser;
    } finally {
      if (version === requestVersion.current) setLoading(false);
    }
  }, []);

  const logout = useCallback(async () => {
    const version = ++requestVersion.current;
    try {
      await authApi.logout();
      if (version === requestVersion.current) {
        setUser(null);
        setSessionError(null);
      }
    } catch (error) {
      const status = error.response?.status;
      if (version === requestVersion.current && status === 401) {
        setUser(null);
        setSessionError(null);
      }
      throw error;
    } finally {
      if (version === requestVersion.current) setLoading(false);
    }
  }, []);

  const value = useMemo(
    () => ({ user, loading, sessionError, register, login, logout }),
    [user, loading, sessionError, register, login, logout],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
