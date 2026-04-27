(function (Drupal, once, drupalSettings) {
  const NODE_WIDTH = 184;
  const ROOT_NODE_WIDTH = 168;
  const NODE_HEIGHT = 78;
  const ROOT_NODE_HEIGHT = 60;
  const HORIZONTAL_GAP = 28;
  const LEVEL_GAP = 128;
  const PADDING_X = 48;
  const PADDING_Y = 36;

  function buildHierarchy(nodes) {
    const byId = new Map();
    nodes.forEach((node) => {
      byId.set(node.id, { ...node, children: [] });
    });

    byId.forEach((node) => {
      if (node.parent && byId.has(node.parent)) {
        byId.get(node.parent).children.push(node.id);
      }
    });

    byId.forEach((node) => {
      node.children.sort((leftId, rightId) => {
        const left = byId.get(leftId);
        const right = byId.get(rightId);
        if ((left.roleWeight || 0) !== (right.roleWeight || 0)) {
          return (left.roleWeight || 0) - (right.roleWeight || 0);
        }
        return (left.label || '').localeCompare(right.label || '');
      });
    });

    return byId;
  }

  function visibleLayout(byId, rootId, collapsed) {
    const widths = new Map();
    const positions = new Map();
    let maxDepth = 0;

    function measure(nodeId, depth) {
      const node = byId.get(nodeId);
      if (!node) {
        return 0;
      }
      maxDepth = Math.max(maxDepth, depth);
      if (collapsed.has(nodeId) || !node.children.length) {
        widths.set(nodeId, 1);
        return 1;
      }

      const width = node.children.reduce((sum, childId) => sum + measure(childId, depth + 1), 0);
      widths.set(nodeId, Math.max(1, width));
      return widths.get(nodeId);
    }

    function assign(nodeId, depth, startUnit) {
      const node = byId.get(nodeId);
      if (!node) {
        return;
      }

      const widthUnits = widths.get(nodeId) || 1;
      let xUnit = startUnit + (widthUnits / 2);
      if (!collapsed.has(nodeId) && node.children.length) {
        let cursor = startUnit;
        node.children.forEach((childId) => {
          assign(childId, depth + 1, cursor);
          cursor += widths.get(childId) || 1;
        });
        const firstChild = positions.get(node.children[0]);
        const lastChild = positions.get(node.children[node.children.length - 1]);
        if (firstChild && lastChild) {
          xUnit = (firstChild.xUnit + lastChild.xUnit) / 2;
        }
      }

      positions.set(nodeId, { xUnit, depth });
    }

    const totalUnits = measure(rootId, 0);
    assign(rootId, 0, 0);

    const visibleIds = Array.from(positions.keys());
    const points = visibleIds.map((nodeId) => {
      const node = byId.get(nodeId);
      const position = positions.get(nodeId);
      return {
        ...node,
        x: position.xUnit + 1,
        y: maxDepth - position.depth + 1,
        depth: position.depth
      };
    });

    const byPointId = new Map(points.map((point) => [point.id, point]));
    const edges = [];
    points.forEach((point) => {
      (point.children || []).forEach((childId) => {
        if (byPointId.has(childId)) {
          edges.push({ from: point.id, to: childId });
        }
      });
    });

    return {
      points,
      byPointId,
      edges,
      maxDepth,
      totalUnits: Math.max(1, totalUnits)
    };
  }

  function nodeColors(point) {
    if (point.id === 'board') {
      return { fill: '#111827', stroke: '#111827', text: '#ffffff', subtitle: '#d1d5db' };
    }
    if (point.paused) {
      return { fill: '#f3f4f6', stroke: '#94a3b8', text: '#334155', subtitle: '#64748b' };
    }
    if (point.id === 'ceo-copilot-2') {
      return { fill: '#dbeafe', stroke: '#2563eb', text: '#1d4ed8', subtitle: '#1e3a8a' };
    }
    return { fill: '#ffffff', stroke: '#64748b', text: '#0f172a', subtitle: '#475569' };
  }

  function drawRoundedRect(context, left, top, width, height, radius) {
    context.beginPath();
    context.moveTo(left + radius, top);
    context.lineTo(left + width - radius, top);
    context.quadraticCurveTo(left + width, top, left + width, top + radius);
    context.lineTo(left + width, top + height - radius);
    context.quadraticCurveTo(left + width, top + height, left + width - radius, top + height);
    context.lineTo(left + radius, top + height);
    context.quadraticCurveTo(left, top + height, left, top + height - radius);
    context.lineTo(left, top + radius);
    context.quadraticCurveTo(left, top, left + radius, top);
    context.closePath();
  }

  function drawWrappedText(context, text, x, startY, maxWidth, lineHeight, font, color) {
    const words = String(text || '').split(/\s+/).filter(Boolean);
    const lines = [];
    let current = '';

    context.font = font;
    context.fillStyle = color;

    words.forEach((word) => {
      const candidate = current ? `${current} ${word}` : word;
      if (context.measureText(candidate).width > maxWidth && current) {
        lines.push(current);
        current = word;
      }
      else {
        current = candidate;
      }
    });

    if (current) {
      lines.push(current);
    }

    lines.forEach((line, index) => {
      context.fillText(line, x, startY + (index * lineHeight));
    });

    return lines.length;
  }

  const orgChartPlugin = {
    id: 'drupalLanggraphOrgChart',
    beforeDatasetsDraw(chart, args, options) {
      const layout = options.layout;
      if (!layout) {
        return;
      }

      const { ctx, scales } = chart;
      ctx.save();
      ctx.strokeStyle = '#cbd5e1';
      ctx.lineWidth = 2;

      layout.edges.forEach((edge) => {
        const from = layout.byPointId.get(edge.from);
        const to = layout.byPointId.get(edge.to);
        if (!from || !to) {
          return;
        }

        const fromX = scales.x.getPixelForValue(from.x);
        const fromY = scales.y.getPixelForValue(from.y);
        const toX = scales.x.getPixelForValue(to.x);
        const toY = scales.y.getPixelForValue(to.y);
        const fromHeight = from.id === 'board' ? ROOT_NODE_HEIGHT : NODE_HEIGHT;
        const toHeight = to.id === 'board' ? ROOT_NODE_HEIGHT : NODE_HEIGHT;
        const middleY = fromY + ((toY - fromY) / 2);

        ctx.beginPath();
        ctx.moveTo(fromX, fromY + (fromHeight / 2));
        ctx.lineTo(fromX, middleY);
        ctx.lineTo(toX, middleY);
        ctx.lineTo(toX, toY - (toHeight / 2));
        ctx.stroke();
      });

      ctx.restore();
    },
    afterDatasetsDraw(chart, args, options) {
      const layout = options.layout;
      if (!layout) {
        return;
      }

      const { ctx, scales } = chart;
      ctx.save();
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';

      layout.points.forEach((point) => {
        const colors = nodeColors(point);
        const x = scales.x.getPixelForValue(point.x);
        const y = scales.y.getPixelForValue(point.y);
        const width = point.id === 'board' ? ROOT_NODE_WIDTH : NODE_WIDTH;
        const height = point.id === 'board' ? ROOT_NODE_HEIGHT : NODE_HEIGHT;
        const left = x - (width / 2);
        const top = y - (height / 2);
        const toggleHint = point.children && point.children.length
          ? (options.collapsed.includes(point.id) ? 'Expand' : 'Collapse')
          : '';

        drawRoundedRect(ctx, left, top, width, height, 14);
        ctx.fillStyle = colors.fill;
        ctx.strokeStyle = colors.stroke;
        ctx.lineWidth = 2;
        ctx.fill();
        ctx.stroke();

        const labelLines = drawWrappedText(ctx, point.label, x, top + 22, width - 18, 16, '600 12px sans-serif', colors.text);
        const subtitle = point.id === 'board'
          ? point.subtitle
          : `${point.subtitle}${toggleHint ? ` • ${toggleHint}` : ''}`;
        ctx.font = '11px sans-serif';
        ctx.fillStyle = colors.subtitle;
        ctx.fillText(subtitle, x, top + 24 + (labelLines * 15));
      });

      ctx.restore();
    }
  };

  Drupal.behaviors.drupalLanggraphOrgChart = {
    attach(context) {
      const canvases = once('drupal-langgraph-org-chart', '.drupal-langgraph-org-chart__canvas', context);
      const settings = drupalSettings.drupalLanggraph && drupalSettings.drupalLanggraph.orgChartDiagram;
      if (!canvases.length || !settings || typeof Chart === 'undefined') {
        return;
      }

      canvases.forEach((canvas) => {
        const hierarchy = buildHierarchy(settings.nodes || []);
        const initialCollapsed = Array.isArray(settings.initialCollapsed) ? settings.initialCollapsed : [];
        const collapsed = new Set(initialCollapsed);
        const wrapper = canvas.closest('.drupal-langgraph-org-chart__canvas-wrapper');
        let chart;

        function render() {
          const layout = visibleLayout(hierarchy, 'board', collapsed);
          const width = Math.max(
            (wrapper ? wrapper.clientWidth : canvas.parentElement.clientWidth) - 24,
            (layout.totalUnits * (NODE_WIDTH + HORIZONTAL_GAP)) + (PADDING_X * 2)
          );
          const height = Math.max(
            320,
            ((layout.maxDepth + 1) * LEVEL_GAP) + (PADDING_Y * 2)
          );

          canvas.width = width;
          canvas.height = height;
          canvas.style.width = `${width}px`;
          canvas.style.height = `${height}px`;

          const dataset = layout.points.map((point) => ({
            x: point.x,
            y: point.y,
            id: point.id,
            label: point.label,
            subtitle: point.subtitle,
            paused: point.paused,
            children: point.children
          }));

          if (chart) {
            chart.destroy();
          }

          chart = new Chart(canvas.getContext('2d'), {
            type: 'scatter',
            data: {
              datasets: [{
                data: dataset,
                pointRadius(context) {
                  return context.raw && context.raw.id === 'board' ? 52 : 72;
                },
                pointHoverRadius(context) {
                  return context.raw && context.raw.id === 'board' ? 56 : 76;
                },
                pointBackgroundColor: 'rgba(0,0,0,0)',
                pointBorderColor: 'rgba(0,0,0,0)'
              }]
            },
            options: {
              responsive: false,
              animation: false,
              maintainAspectRatio: false,
              layout: {
                padding: {
                  top: PADDING_Y,
                  right: PADDING_X,
                  bottom: PADDING_Y,
                  left: PADDING_X
                }
              },
              plugins: {
                legend: { display: false },
                tooltip: {
                  callbacks: {
                    label(item) {
                      const raw = item.raw || {};
                      return `${raw.label || raw.id} (${raw.subtitle || ''})`;
                    }
                  }
                },
                drupalLanggraphOrgChart: {
                  layout,
                  collapsed: Array.from(collapsed)
                }
              },
              scales: {
                x: {
                  display: false,
                  min: 0,
                  max: layout.totalUnits + 1
                },
                y: {
                  display: false,
                  min: 0,
                  max: layout.maxDepth + 2
                }
              },
              onClick(event, elements, chartInstance) {
                const matches = chartInstance.getElementsAtEventForMode(event, 'nearest', { intersect: true }, false);
                if (!matches.length) {
                  return;
                }

                const raw = chartInstance.data.datasets[0].data[matches[0].index];
                if (!raw || !raw.children || !raw.children.length || raw.id === 'board') {
                  return;
                }

                if (collapsed.has(raw.id)) {
                  collapsed.delete(raw.id);
                }
                else {
                  collapsed.add(raw.id);
                }
                render();
              }
            },
            plugins: [orgChartPlugin]
          });
        }

        render();
        window.addEventListener('resize', render, { passive: true });
      });
    }
  };
})(Drupal, once, drupalSettings);
