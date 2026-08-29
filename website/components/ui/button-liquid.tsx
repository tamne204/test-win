"use client";

import React from "react";
import { motion, HTMLMotionProps } from "framer-motion";
import { cn } from "../../lib/utils";

export interface ButtonLiquidProps extends HTMLMotionProps<"button"> {
  children: React.ReactNode;
  className?: string;
  variant?: "shimmer" | "glass" | "outline" | "danger";
  size?: "sm" | "md" | "lg";
  shimmerColor?: string;
}

export function ButtonLiquid({
  children,
  className,
  variant = "shimmer",
  size = "md",
  shimmerColor = "#64d2ff",
  disabled,
  ...props
}: ButtonLiquidProps) {
  const sizeClasses = {
    sm: "px-3 py-1.5 text-xs rounded-lg min-h-[32px]",
    md: "px-5 py-2.5 text-sm rounded-xl min-h-[42px]",
    lg: "px-8 py-3.5 text-base rounded-2xl min-h-[50px] font-bold",
  };

  const variantClasses = {
    shimmer: "shimmer-button text-white",
    glass:
      "bg-[rgba(255,255,255,0.08)] backdrop-blur-md border border-[rgba(255,255,255,0.12)] text-white hover:bg-[rgba(255,255,255,0.16)] hover:border-[rgba(255,255,255,0.25)] shadow-[0_4px_16px_rgba(0,0,0,0.4)]",
    outline:
      "bg-transparent border border-[rgba(255,255,255,0.15)] text-white hover:border-[#0a84ff] hover:text-[#64d2ff]",
    danger:
      "bg-[#ff453a] border border-[rgba(255,255,255,0.15)] text-white shadow-[0_4px_16px_rgba(255,69,58,0.4)] hover:brightness-110",
  };

  return (
    <motion.button
      whileHover={!disabled ? { scale: 1.02, filter: "brightness(1.08)" } : undefined}
      whileTap={!disabled ? { scale: 0.98 } : undefined}
      disabled={disabled}
      className={cn(
        "relative inline-flex items-center justify-center gap-2 font-semibold transition-all duration-200 outline-none disabled:opacity-50 disabled:cursor-not-allowed",
        sizeClasses[size],
        variantClasses[variant],
        className
      )}
      {...props}
    >
      <span className="relative z-10 flex items-center gap-2">{children}</span>
    </motion.button>
  );
}
