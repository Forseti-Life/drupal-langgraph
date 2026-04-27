(function (Drupal, once) {
  Drupal.behaviors.drupalLanggraphFlowVisualization = {
    attach(context) {
      const diagrams = once('drupal-langgraph-flow-visualization', '.drupal-langgraph-mermaid', context);
      if (!diagrams.length || typeof mermaid === 'undefined') {
        return;
      }

      mermaid.initialize({
        startOnLoad: false,
        securityLevel: 'strict',
        theme: 'neutral'
      });

      diagrams.forEach((diagram, index) => {
        const source = diagram.textContent || '';
        if (!source.trim()) {
          return;
        }

        mermaid.render(`drupal-langgraph-flow-${index}`, source).then(({ svg, bindFunctions }) => {
          diagram.innerHTML = svg;
          if (typeof bindFunctions === 'function') {
            bindFunctions(diagram);
          }
        }).catch(() => {
          diagram.classList.add('drupal-langgraph-mermaid--failed');
        });
      });
    }
  };
})(Drupal, once);
