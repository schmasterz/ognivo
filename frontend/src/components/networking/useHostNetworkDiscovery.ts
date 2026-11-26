"use client";

import { useState, useEffect, useRef } from "react";
import { api, HostDiscoveredNetwork } from "@/lib/api";

export interface UseHostNetworkDiscoveryOptions {
  /**
   * Optional callback invoked when host networks are successfully discovered
   * (and the request has not been cancelled). Stored in a ref so inline
   * callbacks do not re-trigger discovery on render.
   */
  onDiscovered?: (networks: HostDiscoveredNetwork[]) => void;
  /**
   * Whether to clear `discoveredNetworks` to `[]` when `hostId` is null or
   * undefined. Defaults to `true` (matching CreateIpPoolModal); EditIpPoolModal
   * returns early without clearing (`false`).
   */
  clearOnEmptyHost?: boolean;
}

export interface UseHostNetworkDiscoveryResult {
  discoveredNetworks: HostDiscoveredNetwork[];
  discoveringHost: boolean;
}

/**
 * Discovers available network interfaces on the specified host node via
 * `api.adminGetHostResources(hostId)`.
 *
 * Preserves the modal discovery semantics: loading state updates are deferred
 * via `queueMicrotask`, stale responses are ignored via a local `cancelled`
 * flag, and errors are silently swallowed while resetting
 * `discoveredNetworks` to `[]`.
 */
export function useHostNetworkDiscovery(
  hostId: number | null | undefined,
  options: UseHostNetworkDiscoveryOptions = {}
): UseHostNetworkDiscoveryResult {
  const { onDiscovered, clearOnEmptyHost = true } = options;

  const [discoveredNetworks, setDiscoveredNetworks] = useState<HostDiscoveredNetwork[]>([]);
  const [discoveringHost, setDiscoveringHost] = useState(false);

  const onDiscoveredRef = useRef(onDiscovered);
  useEffect(() => {
    onDiscoveredRef.current = onDiscovered;
  });

  useEffect(() => {
    if (!hostId) {
      if (clearOnEmptyHost) {
        let cancelled = false;
        queueMicrotask(() => {
          if (!cancelled) {
            setDiscoveredNetworks([]);
          }
        });
        return () => {
          cancelled = true;
        };
      }
      return;
    }

    let cancelled = false;
    queueMicrotask(() => {
      if (!cancelled) {
        setDiscoveringHost(true);
      }
    });

    api
      .adminGetHostResources(hostId)
      .then((res) => {
        if (cancelled) return;
        const nets = res.networks || [];
        setDiscoveredNetworks(nets);
        onDiscoveredRef.current?.(nets);
      })
      .catch(() => {
        if (!cancelled) {
          setDiscoveredNetworks([]);
        }
      })
      .finally(() => {
        if (!cancelled) {
          setDiscoveringHost(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [hostId, clearOnEmptyHost]);

  return {
    discoveredNetworks,
    discoveringHost,
  };
}
