import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import * as authApi from "../api/auth";
import http from "../api/http";
import { AuthContext } from "./AuthContext";

const noticeKey = "supportops-session-notice";
const disabledMessage = "Votre compte a été désactivé. Contactez un administrateur.";

function savedNotice() {
  try { return sessionStorage.getItem(noticeKey); } catch { return null; }
}

function saveNotice(notice) {
  try {
    if (notice) sessionStorage.setItem(noticeKey, notice);
    else sessionStorage.removeItem(noticeKey);
  } catch {
    // La notification reste disponible en mémoire si le stockage est désactivé.
  }
}

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
  const [sessionNotice, setSessionNotice] = useState(savedNotice);
  const requestVersion = useRef(0);
  const sessionEpoch = useRef(0);

  const clearNotice = useCallback(() => {
    setSessionNotice(null);
    saveNotice(null);
  }, []);

  useEffect(() => {
    const requestInterceptor = http.interceptors.request.use((config) => {
      // Une ancienne réponse ne doit pas effacer une connexion plus récente.
      config._supportOpsAuthEpoch = sessionEpoch.current;
      return config;
    }, undefined, { synchronous: true });
    const responseInterceptor = http.interceptors.response.use(
      (response) => response,
      (error) => {
        const status = error.response?.status;
        const disabled = status === 403 && error.response?.data?.code === "account_disabled";
        const currentSession = error.config?._supportOpsAuthEpoch === sessionEpoch.current;
        const invalidCredentials = status === 401 && error.config?.url === "/api/login";
        if (currentSession && !invalidCredentials && (status === 401 || disabled)) {
          ++sessionEpoch.current;
          ++requestVersion.current;
          setUser(null);
          setSessionError(null);
          setLoading(false);
          if (disabled) {
            setSessionNotice(disabledMessage);
            saveNotice(disabledMessage);
          }
        }
        return Promise.reject(error);
      },
    );
    return () => {
      http.interceptors.request.eject(requestInterceptor);
      http.interceptors.response.eject(responseInterceptor);
    };
  }, []);

  useEffect(() => {
    let active = true;
    const controller = new AbortController();
    const version = ++requestVersion.current;
    authApi.getCurrentUser(controller.signal)
      .then((currentUser) => {
        if (active && version === requestVersion.current) {
          ++sessionEpoch.current;
          setUser(currentUser);
          setSessionError(null);
          clearNotice();
        }
      })
      .catch((error) => {
        if (!active || version !== requestVersion.current) return;
        setSessionError(sessionErrorMessage(error));
      })
      .finally(() => {
        if (active && version === requestVersion.current) setLoading(false);
      });
    return () => {
      active = false;
      controller.abort();
    };
  }, [clearNotice]);

  const refreshUser = useCallback(async (updatedUser) => {
    ++sessionEpoch.current;
    const version = ++requestVersion.current;
    const nextUser = updatedUser ?? await authApi.getCurrentUser();
    if (version === requestVersion.current) {
      if (!nextUser.is_active) {
        setUser(null);
        setSessionNotice(disabledMessage);
        saveNotice(disabledMessage);
      } else {
        setUser(nextUser);
        clearNotice();
      }
      setSessionError(null);
    }
    return nextUser;
  }, [clearNotice]);

  const login = useCallback(async (form) => {
    ++sessionEpoch.current;
    const version = ++requestVersion.current;
    try {
      const nextUser = await authApi.login(form);
      if (version === requestVersion.current) {
        ++sessionEpoch.current;
        setUser(nextUser);
        setSessionError(null);
        clearNotice();
      }
      return nextUser;
    } finally {
      if (version === requestVersion.current) setLoading(false);
    }
  }, [clearNotice]);

  const logout = useCallback(async () => {
    ++sessionEpoch.current;
    const version = ++requestVersion.current;
    try {
      await authApi.logout();
      if (version === requestVersion.current) {
        ++sessionEpoch.current;
        setUser(null);
        setSessionError(null);
        clearNotice();
      }
    } finally {
      if (version === requestVersion.current) setLoading(false);
    }
  }, [clearNotice]);

  const value = useMemo(
    () => ({ user, loading, sessionError, sessionNotice, login, logout, refreshUser }),
    [user, loading, sessionError, sessionNotice, login, logout, refreshUser],
  );
  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}
