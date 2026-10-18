import { useCallback, useEffect, useRef, useState } from "react";
import { getDashboard } from "../api/dashboard";
import { ticketError } from "../tickets/ticketUi";

export default function useDashboardData(user) {
  const identity = user?.is_active ? `${user.id}:${user.role}:${Boolean(user.is_active)}` : null;
  const [state, setState] = useState({ identity: null, data: null, pending: true, error: null });
  const currentRequest = useRef(null);
  const inFlight = useRef(false);

  const startRequest = useCallback(() => {
    currentRequest.current?.abort();
    const controller = new AbortController();
    currentRequest.current = controller;
    const isCurrent = () => !controller.signal.aborted && currentRequest.current === controller;

    const promise = (async () => {
      if (!identity) return false;
      inFlight.current = true;
      try {
        const data = await getDashboard(controller.signal);
        if (!isCurrent()) return false;
        setState({ identity, data, pending: false, error: null });
        return true;
      } catch (failure) {
        if (!isCurrent()) return false;
        const error = ticketError(failure);
        if (error.kind === "cancelled") return false;
        setState({ identity, data: null, pending: false, error });
        return false;
      } finally {
        if (isCurrent()) inFlight.current = false;
      }
    })();

    return promise;
  }, [identity]);

  useEffect(() => {
    startRequest();
    return () => currentRequest.current?.abort();
  }, [startRequest]);

  const refresh = useCallback(() => {
    if (!identity || inFlight.current) return Promise.resolve(false);
    setState((previous) => ({
      identity, data: previous.identity === identity ? previous.data : null, pending: true, error: null,
    }));
    return startRequest();
  }, [identity, startRequest]);

  const currentIdentity = identity !== null && state.identity === identity;

  return {
    data: currentIdentity ? state.data : null,
    error: currentIdentity ? state.error : null,
    pending: Boolean(identity) && (!currentIdentity || state.pending),
    refresh,
  };
}
