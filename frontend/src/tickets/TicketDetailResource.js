import { useCallback, useEffect, useRef, useState } from "react";
import { ticketError } from "./ticketUi";

export function useTicketDetailResource(loader) {
  const [state, setState] = useState({ result: null, pending: true, error: null, source: loader });
  const currentRequest = useRef(null);

  const startRequest = useCallback(() => {
    currentRequest.current?.abort();
    const controller = new AbortController();
    currentRequest.current = controller;
    const isCurrent = () => !controller.signal.aborted && currentRequest.current === controller;

    const promise = (async () => {
      try {
        const result = await loader(controller.signal);
        if (!isCurrent()) return false;
        setState({ result, pending: false, error: null, source: loader });
        return true;
      } catch (failure) {
        if (!isCurrent()) return false;
        const error = ticketError(failure);
        if (error.kind === "cancelled") return false;
        setState((previous) => ({
          result: ["network", "server"].includes(error.kind) ? previous.result : null,
          pending: false, error, source: loader,
        }));
        return false;
      }
    })();
    return { controller, promise };
  }, [loader]);

  const load = useCallback(() => {
    setState((previous) => ({ ...previous, pending: true, error: null, source: loader }));
    return startRequest().promise;
  }, [loader, startRequest]);

  const invalidate = useCallback((error) => {
    currentRequest.current?.abort();
    setState({ result: null, pending: false, error, source: loader });
  }, [loader]);

  useEffect(() => {
    startRequest();
    return () => currentRequest.current?.abort();
  }, [startRequest]);

  return { ...state, pending: state.pending || state.source !== loader, error: state.source === loader ? state.error : null, load, invalidate };
}
