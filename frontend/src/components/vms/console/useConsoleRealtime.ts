"use client";

import { useEffect, useRef, useState } from "react";
import { VirtualMachine } from "@/lib/api";
import { getEcho } from "@/lib/echo";

export interface UseConsoleRealtimeParams {
  vm: VirtualMachine | null;
  userId: number | null | undefined;
  onAgentStarted: (vmId: number) => void;
}

interface StatusUpdatedEvent {
  vm?: Partial<VirtualMachine> & { id?: number };
}

interface AgentStartedEvent {
  vm_id?: number;
  vm?: { id?: number };
  agent_ready?: boolean;
}

const AGENT_EVENT_DEDUP_MS = 500;

/**
 * Subscribes to per-user Laravel Echo VM status and guest-agent events for the
 * active console modal without tearing down the shared `users.{userId}` channel.
 */
export function useConsoleRealtime({
  vm,
  userId,
  onAgentStarted,
}: UseConsoleRealtimeParams): VirtualMachine | null {
  const [realtimeVm, setRealtimeVm] = useState<VirtualMachine | null>(() => vm);

  const vmRef = useRef<VirtualMachine | null>(vm);
  const onAgentStartedRef = useRef(onAgentStarted);

  useEffect(() => {
    vmRef.current = vm;
    onAgentStartedRef.current = onAgentStarted;
  }, [vm, onAgentStarted]);

  useEffect(() => {
    let ignore = false;
    queueMicrotask(() => {
      if (!ignore) {
        setRealtimeVm(vm);
      }
    });
    return () => {
      ignore = true;
    };
  }, [vm]);

  const vmId = vm?.id ?? null;
  const isVm = vm?.instance_type === "virtual-machine";

  useEffect(() => {
    if (!vmId || !userId) {
      return;
    }

    const echo = getEcho();
    if (!echo) {
      return;
    }

    const userChannel = echo.private(`users.${userId}`);
    let lastAgentEvent: AgentStartedEvent | null = null;
    let lastAgentEventAt = 0;

    const handleStatusUpdate = (event: StatusUpdatedEvent) => {
      const updated = event?.vm;
      if (!updated || Number(updated.id) !== Number(vmId)) {
        return;
      }

      setRealtimeVm((prev) => {
        const base = prev && prev.id === vmId ? prev : vmRef.current;
        if (!base) {
          return prev;
        }
        return {
          ...base,
          ...updated,
          id: base.id,
          status: updated.status ?? base.status,
        };
      });
    };

    const handleAgentStarted = (event: AgentStartedEvent) => {
      const targetVmId = Number(event?.vm_id ?? event?.vm?.id);
      if (targetVmId !== Number(vmId)) {
        return;
      }

      const now = Date.now();
      if (
        event === lastAgentEvent ||
        now - lastAgentEventAt < AGENT_EVENT_DEDUP_MS
      ) {
        return;
      }
      lastAgentEvent = event;
      lastAgentEventAt = now;

      onAgentStartedRef.current(vmId);
    };

    userChannel.listen(".VirtualMachineStatusUpdated", handleStatusUpdate);
    userChannel.listen("VirtualMachineStatusUpdated", handleStatusUpdate);

    if (isVm) {
      userChannel.listen(".VirtualMachineAgentStarted", handleAgentStarted);
      userChannel.listen("VirtualMachineAgentStarted", handleAgentStarted);
    }

    return () => {
      try {
        userChannel.stopListening(
          ".VirtualMachineStatusUpdated",
          handleStatusUpdate
        );
        userChannel.stopListening(
          "VirtualMachineStatusUpdated",
          handleStatusUpdate
        );
        if (isVm) {
          userChannel.stopListening(
            ".VirtualMachineAgentStarted",
            handleAgentStarted
          );
          userChannel.stopListening(
            "VirtualMachineAgentStarted",
            handleAgentStarted
          );
        }
      } catch {
        // ignore cleanup issues
      }
    };
  }, [vmId, isVm, userId]);

  return vm ? (realtimeVm && realtimeVm.id === vm.id ? realtimeVm : vm) : null;
}
